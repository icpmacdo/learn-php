# Part 1 quiz — answers

Answers to [quiz.md](quiz.md). Each one is short on purpose: if your answer
hit the same core reason, you got it, even if the wording differs.

---

## Request lifecycle

**1.** The path: nginx's `try_files $uri /index.php$is_args$args` finds no
file at `/links/aZ3kQ9x`, so it forwards the request over FastCGI to php-fpm
running `public/index.php`. That file boots `App\Kernel`, whose `HttpKernel`
wraps the request in a `Request` object and dispatches it. The **router**
matches the path against routes declared as PHP attributes on controller
methods — here `#[Route('/{code}', name: 'link_show', ...)]` on
`LinkController::show()` (combined with the class-level `#[Route('/links')]`
prefix). The DI container then builds `LinkController` with its constructor
dependencies and calls `show('aZ3kQ9x')`, which returns a `Response` that
flows back out.

Two features that depend on the single front controller:

- **The uniform JSON error contract.** `JsonExceptionListener` hooks the
  kernel's `kernel.exception` event, so *every* failure — unknown route,
  wrong method, unknown code, uncaught exception — comes out in one shape
  (`CrossCuttingCest` proves it). With one-file-per-URL there is no shared
  kernel to hook; each file would hand-roll its own error output.
- **Routing features like `requirements` and method matching.** The
  `[0-9A-Za-z]+` constraint and `methods: ['GET']` only exist because a
  router sees the request before any application code. (Also acceptable: the
  DI container itself — it's built once per request cycle by the kernel;
  there's no place to put it if every URL is its own script.)

**2.** When a controller throws, `HttpKernel` catches the exception and
dispatches a `kernel.exception` event instead of letting PHP die. Listeners
get an `ExceptionEvent`; `JsonExceptionListener` (registered via
`#[AsEventListener(event: 'kernel.exception', priority: -8)]`) calls
`$event->setResponse(...)` with the JSON body, and the kernel sends that
response.

Why a listener, not per-controller `try/catch`: the error *shape* is an
API-wide contract, not per-endpoint logic. A listener guarantees the shape
even for exceptions no controller ever sees — the router's 404 for an unknown
path and 405 for a wrong method are thrown before any controller runs.
Duplicated `try/catch` blocks would miss those entirely and drift apart over
time.

Priority `-8`: listeners run highest-priority-first. Symfony's exception
*logging* listener runs at priority `0` — it must run first so errors still
land in the log. Symfony's default *error renderer* (which produces an HTML
error page) runs at `-128` — our listener must run before it, and by setting
a response it wins. `-8` sits between the two: after logging, before HTML.

## Dependency injection

**3.** The `???` is the tell: `LinkCreator`'s constructor needs a
`ManagerRegistry` — a Doctrine service you cannot meaningfully `new` yourself
(it's wired to connection config, entity metadata, the entity-manager
lifecycle). You'd have to fetch it from the container anyway, so the `new`
buys nothing: **dependencies cascade**, and hand-construction just pushes the
container lookup one level down while hard-coding everything above it.

The other problems:

- **Testability.** `LinkCreatorTest` works precisely because `LinkCreator`
  *receives* its `ManagerRegistry` — the test hands it a mock whose `flush()`
  throws `UniqueConstraintViolationException` on demand. If the controller
  hard-coded `new LinkCreator(new CodeGenerator(), ...)`, nothing could
  substitute a test double without editing the controller; the collision path
  would be untestable without a real database and a real collision.
- **Configuration is centralized.** Today the container builds one shared
  wiring graph from `services.yaml`. Want 8-character codes, or a
  deterministic generator in tests? Swap or decorate one service definition.
  With inline `new`, every construction site must be found and edited.
- (Also fine: duplication — every future user of `LinkCreator` repeats the
  construction; and the controller now depends on *concrete constructors*,
  so any signature change to `LinkCreator` or `CodeGenerator` breaks it.)

**4.** In `config/services.yaml`: the `App\: resource: '../src/'` block
registers every class under `src/` as a service, and `_defaults: autowire:
true` turns on autowiring. To build `LinkCreator`, the container reflects on
its constructor signature — `CodeGenerator $codeGenerator, ManagerRegistry
$registry` — and resolves each **type-hint** to the service of that type:
`CodeGenerator` is the `src/` service of the same name; `ManagerRegistry` is
provided by the Doctrine bundle. Type-hints *are* the configuration.
(`autoconfigure: true` is what makes `#[AsEventListener]` on
`JsonExceptionListener` register it with the dispatcher without any YAML.)

**5.** Constructor injection for dependencies the whole class needs across
actions: `LinkRepository` is used by `list()`, `show()`, and `delete()`, and
`LinkResponseMapper` by every JSON-returning action. Action-argument
injection for dependencies only one action uses: only `create()` validates
input or creates links, so `ValidatorInterface` and `LinkCreator` are
resolved only when that action runs — a `GET /links` never touches them, and
the constructor signature stays an honest list of what the controller as a
whole depends on. Rule of thumb: shared → constructor, single-action →
action argument.

## Doctrine

**6.** `schema:update --force` diffs the current database against the
entities and applies whatever DDL closes the gap — unreviewed, unrecorded,
and computed per environment. A migration is the opposite on all three
counts: a **reviewed** SQL file (`Version20260718201115.php` was checked by
hand, per its docblock), in **version control**, applied in **recorded
order** everywhere — the entrypoint runs the same file against `app` and
`app_test`, and any future environment replays the identical history.

Concrete data-loss example: rename `Link::$url` to `Link::$targetUrl`. The
diff has no idea it's a rename — it sees "column `url` missing from entity,
column `target_url` missing from table" and emits `DROP COLUMN url, ADD
COLUMN target_url`, wiping every stored URL. A migration for the same
refactor would be written (or corrected) by a human as `ALTER TABLE ...
RENAME COLUMN`, and you'd see the destructive version in review before it ran
anywhere.

**7.** `persist($link)` sends **no SQL** — it only registers the new object
with Doctrine's unit of work ("include this in the next write"). `flush()` is
the moment of truth: the unit of work computes all pending changes and
executes the SQL (the `INSERT`) inside a transaction. That's why the
collision can only surface at `flush()` — MySQL can't reject a duplicate
`code` against `uniq_link_code` until the `INSERT` actually arrives, so a
`try` around `persist()` alone would catch nothing, ever.

`resetManager()`: when a flush fails, Doctrine **closes** that
EntityManager — it may hold a now-inconsistent unit of work, so it refuses
all further work. Without the reset, retry #2 would call `persist()` on a
closed EM and die. `resetManager()` discards it and issues a fresh one
(`LinkCreatorTest` pins this: exactly 2 `resetManager()` calls for 2 failed
flushes).

**8.** The lost-update interleaving with the read-modify-write version, for a
link with `hits = 4`:

1. Request A loads the entity: reads `hits = 4`.
2. Request B loads the entity: also reads `hits = 4`.
3. A computes `4 + 1`, flushes `UPDATE link SET hits = 5`.
4. B computes `4 + 1`, flushes `UPDATE link SET hits = 5`.

Two redirects, one counted — B silently overwrote A. The window is the gap
between each request's read and its write, and nothing in the ORM closes it.
The DQL version, `SET l.hits = l.hits + 1`, ships the arithmetic to MySQL:
each `UPDATE` reads and writes the current value inside one atomic statement
(row-locked), so concurrent executions serialize — `4 → 5 → 6`. There is no
stale value in PHP because the value never comes to PHP.

**9.** The pre-check version is a **check-then-act race**. Sequence: request
A generates code `x7Kp2Qa`, `SELECT`s, finds it free. Request B generates the
same code, `SELECT`s — *also* free, because A hasn't inserted yet. Both
proceed; both `INSERT`. Best case one gets an unhandled
`UniqueConstraintViolationException` (a 500 the code claims to prevent);
without the unique index, both rows exist and `GET /r/x7Kp2Qa` is ambiguous.
Tests pass because Codeception requests run one at a time — the race needs
concurrency to bite.

The only thing that can guarantee uniqueness is the database's **unique
index** `uniq_link_code`: declared on the entity as
`#[ORM\UniqueConstraint(name: 'uniq_link_code', columns: ['code'])]` in
`src/Entity/Link.php`, created by `migrations/Version20260718201115.php`
(`UNIQUE INDEX uniq_link_code (code)`). MySQL enforces it atomically at
insert time — it cannot be raced, which is why `LinkCreator` treats
"insert and see" as the check.

## SQL injection

**10.** DQL with `:code` becomes a **prepared statement**: MySQL receives the
query in two separate parts. Part one is the SQL text with a placeholder —
`SELECT ... FROM link l0_ WHERE l0_.code = ?` — which MySQL parses and plans
*before* any value exists. Part two is the bound value, sent afterward over
the wire as pure data for slot 1. Since parsing is already done when the
value arrives, the value can never become SQL syntax — `'; DROP TABLE link;
--` is just a 22-character string compared against the `code` column, matching
nothing. Injection requires the attacker's text to reach the parser; here it
structurally cannot.

The injectable rewrite is string interpolation into the DQL:

```php
->andWhere("l.code = '$code'")   // value becomes part of the parsed text
```

Fastest way to see it: tail the dev log while hitting the endpoint —
`docker compose exec php sh -c 'tail -f var/log/dev.log | grep doctrine'`
shows `Executing statement: SELECT ... WHERE l0_.code = ? (parameters:
array{"1":"aZ3kQ9x"}, ...)`. The `?` in the *logged statement* with the value
in a separate `parameters` array is the proof: the value was never in the SQL
text at all.

**11.** No — the regex is not the injection defense, and treating it as one
would be fragile (defenses that depend on every route remembering a regex
eventually miss one). Parameterization is the defense, and it holds even with
the regex deleted.

The regex solves a different problem: **turning impossible codes into clean
routing-level 404s**. The `code` column is `ascii` charset with `ascii_bin`
collation (see `src/Entity/Link.php`), so a non-ASCII path segment — say a
short URL mangled in transit into `/r/héllo` — would reach MySQL as a value
that can't be compared against an ascii column, producing a database error
and a 500. With the requirement, the router rejects `héllo` before any code
runs: a 404 in the standard JSON shape
(`RedirectCest::nonAsciiCodeIs404NotA500` pins exactly this). Two layers, two
jobs: the regex handles *garbage*, parameterization handles *malice*.

## REST choices

**12.** `201 Created` says more than `200`: a new resource now exists as a
result of this request, and the `Location: /links/{code}` header tells the
client canonically *where* — it can follow up with GET/DELETE without
parsing conventions out of the body.

The `400`/`422` split separates two different failures with two different
audiences:

- **400 Bad Request** — "your request is malformed": not JSON, no `url` key,
  or `url` isn't a string. This is a *programming* error in the client's
  request construction; fix the code, not the input. Error has `field: null`
  because no valid field ever existed.
- **422 Unprocessable Entity** — "your request is well-formed but the value
  is invalid": blank, not a URL, wrong scheme, too long. This is *user
  input* feedback, shaped for display: `field: "url"` plus a human message
  from the Validator constraint.

`{"url": 123}` is on the 400 side because it fails the *shape* check — the
`is_string` guard in step 1 of `LinkController::create()` — before validation
ever sees it. `numericUrlValueIs400` is protecting against a **500**: without
the guard, passing an `int` to `CreateLinkRequest`'s `string $url`
constructor parameter would throw a `TypeError`, and a client's malformed
request would read as a server bug.

**13.** Browsers (and many HTTP clients/CDNs) cache a `301` essentially
forever: after the first visit, the browser rewrites `/r/{code}` to the
target itself and never contacts the server again. Every repeat visit becomes
invisible — `incrementHits()` never runs, and the hit counts (a core feature:
they're in every list/detail response) silently undercount with no error
anywhere. `302 Found` is defined as temporary, so clients re-request each
time and every redirect passes through the server. Correct-on-paper
semantics traded away for an observable product requirement.

**14.** `204 No Content` communicates "done, and there is nothing useful to
send back" — the resource is gone, so echoing it would be odd, and an empty
body with a meaningful status is the cleanest contract
(`DeleteLinkCest` asserts the body is literally `''`).

The second `404` communicates the truth at request time: no resource with
that code exists *now*. A `204` on the repeat call would tell the client "I
deleted it just now," which is false, and would hide bugs like a client
deleting with a stale or mistyped code.

This is still idempotent in the sense HTTP cares about — **idempotency is
about server state, not response codes**. After one DELETE or five, the state
is identical: the link is gone. Repeating the request is always safe; only
the status code differs, and it differs by telling each request the truth.

## Test pyramid

**15.**

- **(a)** `CodeGenerator` is pure logic: no framework, no I/O, no
  collaborators — `new CodeGenerator()` and assert on the return value.
  That's what makes its unit tests fast enough to run 200 generations in
  milliseconds and check the base62 alphabet exhaustively. A "unit test" of
  `RedirectController`, by contrast, would mock the repository, call
  `__invoke()`, and assert it returns a `RedirectResponse` — proving nothing
  about what actually matters: that the route matches, the `requirements`
  regex rejects garbage, the `Location` header carries the stored URL, the
  hit really increments in MySQL, and unknown codes yield the JSON 404.
  Every one of those lives in the seams *between* components, so
  `RedirectCest` asserts them over real HTTP.

- **(b)** A real collision requires two links to draw the same code from
  62^7 ≈ 3.5 trillion possibilities — you cannot trigger that from outside
  the API in this lifetime. To force it you'd have to control the randomness:
  pre-seed the database with a known code *and* make `CodeGenerator` produce
  that exact code, i.e. replace the generator anyway — at which point you're
  mocking, just with a database bolted on. The unit test
  (`LinkCreatorTest`) goes straight there: a mocked `EntityManagerInterface`
  throws the same `UniqueConstraintViolationException` real MySQL would, on
  exactly the attempts the test chooses, which also lets it pin the subtle
  part — `resetManager()` must be called after each failed flush because a
  failed flush closes the EM. Rare-condition *reaction* logic is the classic
  unit-test case: the trigger is mocked, the reaction is real code.

- **(c)** `cleanup: true` wraps every test in a database transaction rolled
  back afterward, so each test starts from an empty `link` table and tests
  can't contaminate each other or depend on execution order.
  `ListLinksCest::emptyDatabaseGivesEmptyList` asserts the response is
  exactly `['links' => []]` — without cleanup it fails the moment any
  earlier test (or a previous run) leaves a row behind. The `test` env +
  `app_test` database keeps the suite away from dev data in `app`: you can
  run the tests mid-experiment without them truncating what you were looking
  at, and the entrypoint migrates both databases so `app_test` always has
  the current schema.
