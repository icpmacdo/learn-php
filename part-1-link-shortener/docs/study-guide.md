# Study guide — reading the link-shortener

This is a guided reading of the Part 1 codebase, not reference docs. Read it
with the code open; every section names the exact file to look at. The order
matters: first you follow one real request end to end so nothing stays magic,
then you revisit the same files once per learning objective from
[the spec](../../docs/specs/part-1-link-shortener.md).

One piece of context before anything else, because it shapes everything you're
about to read: **PHP is share-nothing per request**. Unlike a Node or JVM
server, no application object lives between requests. Each HTTP request
executes [`public/index.php`](../public/index.php) from the top, builds (or
loads a cached copy of) the whole framework, handles exactly one request, and
throws everything away. php-fpm keeps worker *processes* warm and opcache
keeps compiled bytecode warm, which is why this isn't slow — but there is no
in-memory state you can lean on. That's why hit counting must go through the
database, and why "the container is compiled and cached" (later) matters.

---

## 1. The life of a request: `POST /links`

Follow one real request:

```sh
curl -i -X POST http://localhost:8080/links \
  -H 'Content-Type: application/json' \
  -d '{"url": "https://example.com/some/long/path"}'
```

### 1.1 nginx — [`docker/nginx/default.conf`](../docker/nginx/default.conf)

The request hits the `nginx` container (port 8080 on your host maps to 80,
see [`compose.yaml`](../compose.yaml)). The config does one thing:

- `try_files $uri /index.php$is_args$args;` — if the path isn't an existing
  file under `public/`, rewrite it to `/index.php`. `/links` is not a file, so
  every API request lands on the **front controller**. One entry point for the
  whole app; the URL structure lives in PHP, not in the web server.
- The `location ~ ^/index\.php(/|$)` block hands the request to
  `fastcgi_pass php:9000` — the php-fpm process in the `php` container,
  speaking FastCGI, addressed by its Compose service name.
- The last block (`location ~ \.php$ { return 404; }`) refuses to execute any
  other PHP file. Only the front controller is ever runnable from the web.

### 1.2 The front controller — [`public/index.php`](../public/index.php)

Nine lines, and this is the entire boot:

- `require .../vendor/autoload_runtime.php` — Composer's autoloader plus the
  Symfony Runtime component. Autoloading is why you will see no `require`
  statements anywhere else: PHP maps the namespace `App\` to `src/` (declared
  in [`composer.json`](../composer.json) under `autoload.psr-4`) and loads
  classes on first use.
- The file returns a closure that builds `new Kernel($env, $debug)` from the
  `APP_ENV` / `APP_DEBUG` environment variables. The Runtime component calls
  it, turns the superglobals into a `Request` object, calls
  `$kernel->handle($request)`, and sends the returned `Response`.

[`src/Kernel.php`](../src/Kernel.php) is deliberately empty — it just mixes in
`MicroKernelTrait`, which knows how to load everything under
[`config/`](../config): bundles from
[`config/bundles.php`](../config/bundles.php), package config from
`config/packages/*.yaml`, service wiring from
[`config/services.yaml`](../config/services.yaml), routes from
[`config/routes.yaml`](../config/routes.yaml).

### 1.3 Routing — attributes on the controller

[`config/routes.yaml`](../config/routes.yaml) doesn't list any routes; it says
"import routes from the controllers". The actual route definitions are PHP
**attributes** (native language metadata, PHP 8's replacement for docblock
annotations) directly on the controller methods in
[`src/Controller/LinkController.php`](../src/Controller/LinkController.php):

- `#[Route('/links')]` on the class is a prefix.
- `#[Route('', name: 'link_create', methods: ['POST'])]` on `create()` means:
  `POST /links` → this method. The `methods:` restriction is why
  `PUT /links` is a 405, not a 404 (the path exists, the verb doesn't —
  pinned by
  [`tests/Api/CrossCuttingCest.php`](../tests/Api/CrossCuttingCest.php)).
- The `{code}` routes carry
  `requirements: ['code' => '[0-9A-Za-z]+']` — the router itself rejects any
  path segment that can't be a base62 code. More on why in §6.

Inside `HttpKernel::handle()` this is an event: the `kernel.request` event
fires, Symfony's `RouterListener` matches the path+method against the compiled
route map and stashes `_controller = LinkController::create` in the request
attributes. See the whole table yourself:

```sh
docker compose exec php php bin/console debug:router
```

### 1.4 The container builds the controller

`LinkController` is never `new`ed by you. The DI container constructs it,
injecting `LinkRepository` and `LinkResponseMapper` (its constructor
arguments), because [`config/services.yaml`](../config/services.yaml) declares
everything under `src/` a service with `autowire: true`. §3 digs into this.

Then the **argument resolver** prepares the arguments of `create(Request
$request, ValidatorInterface $validator, LinkCreator $creator)`: the current
`Request` by type, and the two services by type-hint. Controllers are the one
place where services may be injected per-*method* rather than per-constructor
— handy when only one action needs the validator.

### 1.5 The action — [`src/Controller/LinkController.php`](../src/Controller/LinkController.php), `create()`

Read the three numbered steps in the method body; the split is deliberate:

1. **Transport check → 400.** `json_decode` the raw body; if it isn't a JSON
   object with a string `url`, throw `BadRequestHttpException`. A malformed
   request is not a *validation* failure — the request never got far enough
   to be validated. Note the `!\is_string($body['url'])` guard:
   `{"url": 123}` would otherwise explode later as a PHP `TypeError` (a 500).
   [`tests/Api/CreateLinkCest.php`](../tests/Api/CreateLinkCest.php)
   (`numericUrlValueIs400`) pins exactly this.
2. **Validation → 422.** Build the DTO, run the validator, and if there are
   violations, return them as `{"errors": [{"field": "url", ...}]}` with 422.
3. **Do the work → 201.** Delegate to `LinkCreator`, map the entity to JSON,
   return 201 with a `Location: /links/{code}` header (generated from the
   route name `link_show`, never string-concatenated).

A couple of PHP-isms worth noticing on the way through this file:

- `declare(strict_types=1)` at the top of every file: without it, PHP silently
  coerces argument types (`"123"` passed to an `int` parameter). With it, you
  get a `TypeError`. Always on, everywhere, in this codebase.
- Constructor property promotion + `readonly`:
  `public function __construct(private readonly LinkRepository $links)` both
  declares the property and assigns it — the standard modern-PHP DI idiom.
- `$this->links->findOneByCode($code) ?? throw new NotFoundHttpException(...)`
  — `throw` is an expression in PHP 8, so it composes with `??`.

### 1.6 Validation — [`src/Dto/CreateLinkRequest.php`](../src/Dto/CreateLinkRequest.php)

The constraints (`NotBlank`, `Url(protocols: ['http','https'],
requireTld: true)`, `Length(max: 2048)`) are attributes on a tiny DTO — not on
the entity. That's a layering choice: input validation is its own concern, and
[`src/Entity/Link.php`](../src/Entity/Link.php) is constructed only *after*
validation passes, so an entity holding an invalid URL can never exist. The
`Length(max: 2048)` deliberately matches the `VARCHAR(2048)` column — the
validator enforces at the edge what the schema enforces at the bottom.

### 1.7 The service — [`src/Service/LinkCreator.php`](../src/Service/LinkCreator.php)

`LinkCreator::create()` is the most instructive dozen lines in the project:

- It asks [`src/Service/CodeGenerator.php`](../src/Service/CodeGenerator.php)
  for a random 7-char base62 code (`random_int` is a CSPRNG — codes must not
  be guessable or sequential).
- It constructs the entity and calls `persist()` + `flush()` — the actual
  `INSERT` happens at `flush()` (§4).
- **Collision handling is insert-and-catch, not check-then-insert.** If the
  generated code already exists, MySQL's unique index `uniq_link_code` rejects
  the INSERT, Doctrine surfaces it as `UniqueConstraintViolationException`,
  and the loop regenerates and retries (max `MAX_ATTEMPTS = 5`, then
  [`src/Service/CodeCollisionException.php`](../src/Service/CodeCollisionException.php)
  → 500). A pre-check `SELECT` would be a bug: two concurrent requests could
  both see "code is free" and both insert. The unique index cannot be raced —
  the database is the source of truth for uniqueness.
- The `$this->registry->resetManager()` in the catch: a failed `flush()`
  permanently closes Doctrine's EntityManager, so the retry needs a fresh
  one. This exact behavior is unit-tested in
  [`tests/Unit/LinkCreatorTest.php`](../tests/Unit/LinkCreatorTest.php).

### 1.8 The entity — [`src/Entity/Link.php`](../src/Entity/Link.php)

A plain PHP class with ORM attributes: `#[ORM\Entity]`, `#[ORM\Column]`,
`#[ORM\UniqueConstraint(name: 'uniq_link_code', columns: ['code'])]`. Note:

- `code` is `VARCHAR(7)` with `ascii_bin` charset/collation — case-sensitive
  (base62 needs `a` ≠ `A`; utf8mb4's default collation would treat them as
  equal) and it keeps the unique index small.
- `createdAt` is set in the constructor as UTC `DateTimeImmutable`; there are
  no setters at all. The entity is valid from the moment it exists.
- `$id` is `?int` and null until `flush()` — the database assigns it (§4).

### 1.9 The response — [`src/Http/LinkResponseMapper.php`](../src/Http/LinkResponseMapper.php)

Entity → array, by hand, in one visible place. No serializer, no magic: the
five JSON fields you see in every response are the five lines of `toArray()`.
`shortUrl` is *derived* — the router's `UrlGeneratorInterface` builds an
absolute URL for the `link_redirect` route from the current request's
scheme/host — never stored in the database (store it and it's stale the
moment the host changes).

In `list()` back in the controller, note the mapping call:
`array_map($this->mapper->toArray(...), ...)` — that `(...)` is PHP 8.1's
first-class callable syntax, turning a method into a closure.

### 1.10 When anything goes wrong — [`src/EventListener/JsonExceptionListener.php`](../src/EventListener/JsonExceptionListener.php)

Controllers *throw* (`NotFoundHttpException`, `BadRequestHttpException`);
nothing in this codebase builds a 404 response by hand. Any exception during
`handle()` fires the `kernel.exception` event, and this one listener converts
every error — 404 (unknown code *and* unknown route), 405, 400, 500 — into
the single error shape `{"errors": [{"field": null|string, "message": ...}]}`.
One place, one shape; that's the whole REST error contract.

The `#[AsEventListener(event: 'kernel.exception', priority: -8)]` attribute is
all the registration there is (autoconfiguration picks it up, §3). Priority
−8: after Symfony's exception logger (0), before the default HTML error
renderer (−128) — errors still get logged, but the client never sees an HTML
error page.

The response travels back: kernel → Runtime sends it → php-fpm → FastCGI →
nginx → your terminal. Request over; process state discarded.

---

## 2. MVC as Symfony implements it

Symfony doesn't have a base `Model` class or enforce folder names — MVC here
is a set of conventions you can now map onto files you've already read:

| Role | In this project | Notes |
|---|---|---|
| Model | [`src/Entity/Link.php`](../src/Entity/Link.php), [`src/Repository/LinkRepository.php`](../src/Repository/LinkRepository.php), [`src/Service/LinkCreator.php`](../src/Service/LinkCreator.php), [`src/Service/CodeGenerator.php`](../src/Service/CodeGenerator.php) | Domain state + the operations on it. "Model" is a layer, not a class. |
| View | [`src/Http/LinkResponseMapper.php`](../src/Http/LinkResponseMapper.php) + the `JsonResponse`s | No templates in an API — the "view" is the JSON representation. |
| Controller | [`src/Controller/LinkController.php`](../src/Controller/LinkController.php), [`src/Controller/RedirectController.php`](../src/Controller/RedirectController.php) | Thin translators: HTTP in → call the model → HTTP out. |

The test of "is the controller thin enough": every method in
`LinkController` fits on a screen and contains no SQL, no business rules, no
JSON field names. Deleting a link is `remove()` on the repository; creating
one is `create()` on the service. If you deleted the controllers you would
lose the HTTP surface but no domain knowledge.

Two controller styles are shown on purpose. `LinkController` extends
`AbstractController` (for helpers like `generateUrl()`).
`RedirectController` extends nothing and is a single `__invoke()` — the
`#[AsController]` attribute is enough to opt it into controller argument
resolution. Neither is "more correct"; the point is that a controller is just
a callable the router points at.

Underneath, the "step by step" from the spec's learning objective is the
`HttpKernel` event loop you saw in §1: `kernel.request` (routing) → resolve
controller → resolve arguments → **your code** → `kernel.response` /
`kernel.exception`. Every Symfony feature you'll meet later (firewalls, CORS,
profilers) is just another listener on those same events —
[`JsonExceptionListener`](../src/EventListener/JsonExceptionListener.php) is
your first.

---

## 3. The DI container: wired, not instantiated

Count the `new` keywords in the `src/` tree that create a *service*: zero.
`new` appears only for values — entities, DTOs, responses, exceptions. Every
object with behavior and dependencies (repository, mapper, creator,
generator) arrives through a constructor signature.

The entire wiring configuration is
[`config/services.yaml`](../config/services.yaml), and it's ~10 effective
lines:

- `App\: resource: '../src/'` — every class in `src/` is registered as a
  service, id = its class name.
- `autowire: true` — constructor dependencies are resolved **by type-hint**.
  `LinkCreator` asks for `CodeGenerator`; the container finds the one service
  of that type and passes it. Nobody wrote "LinkCreator needs CodeGenerator"
  anywhere — the constructor *is* the configuration.
- `autoconfigure: true` — classes get framework roles from their
  type/attributes: this is how
  [`JsonExceptionListener`](../src/EventListener/JsonExceptionListener.php)'s
  `#[AsEventListener]` works without any listener registration, and how
  controllers become routable.

Why bother, in an app this small?

1. **The dependency graph is explicit and acyclic.** Read any constructor and
   you know exactly what the class needs. Nothing reaches into a global or a
   singleton.
2. **Substitutability.** [`tests/Unit/LinkCreatorTest.php`](../tests/Unit/LinkCreatorTest.php)
   constructs `LinkCreator` *by hand* with a mocked `ManagerRegistry` — the
   same seam the container uses in production is the seam the test uses. If
   `LinkCreator` did `new EntityManager(...)` internally, that test could not
   exist without a database.
3. **Interface-to-implementation binding happens once.** The controller
   type-hints `ValidatorInterface`; which concrete validator (and its
   configured constraint loaders) shows up is the container's problem.

Two things experienced-you should know so it doesn't feel like magic:

- The container is **compiled**: on first boot Symfony resolves all the
  wiring into plain generated PHP in `var/cache/`, so runtime cost is
  near-zero and misconfiguration fails at compile time, not mid-request.
- You can interrogate it:

  ```sh
  docker compose exec php php bin/console debug:container App
  docker compose exec php php bin/console debug:autowiring Validator
  ```

---

## 4. Doctrine: entity lifecycle, migrations, repositories

### The lifecycle

Doctrine is a *data-mapper* ORM (unlike Active Record à la Rails/Eloquent):
[`src/Entity/Link.php`](../src/Entity/Link.php) has no `save()` method and no
base class — it's a plain object, and the **EntityManager** tracks it from
the outside. The states, as this project uses them:

1. **New** — `new Link($code, $url)` in
   [`LinkCreator`](../src/Service/LinkCreator.php). Doctrine knows nothing
   about it; `$id` is null.
2. **Managed** — `$em->persist($link)` registers it with the EntityManager's
   *unit of work*. Still no SQL.
3. **Flushed** — `$em->flush()` computes what changed and emits the SQL
   (here: one `INSERT`). Only now does MySQL assign the auto-increment id,
   which Doctrine writes back into the entity — this is why `getId(): ?int`
   is nullable, and why the collision exception surfaces at `flush()`, not
   `persist()`.
4. **Removed** — `LinkRepository::remove()` calls `$em->remove($link)` +
   `flush()` → one `DELETE`.

Entities *loaded* from the database (via the repository) are automatically
managed; if you mutated one and flushed, Doctrine would diff it and emit an
`UPDATE`. This project never does that — the only update is the atomic hits
increment, deliberately done in DQL instead (§5 and
[`LinkRepository::incrementHits`](../src/Repository/LinkRepository.php)).

### Migrations

The schema is created exclusively by
[`migrations/Version20260718201115.php`](../migrations/Version20260718201115.php)
— a generated-then-hand-reviewed class whose `up()` contains the literal
`CREATE TABLE link (...)` DDL. Compare it side by side with the attributes in
[`src/Entity/Link.php`](../src/Entity/Link.php): every mapping detail
(`BIGINT UNSIGNED` PK, `ascii_bin` code column, `uniq_link_code`, the `hits`
default) appears in both, because the migration was diffed *from* the
mapping:

```sh
# after changing an entity:
docker compose exec php php bin/console make:migration   # diff -> new class
# read the generated SQL, edit if needed, then:
docker compose exec php php bin/console doctrine:migrations:migrate
```

Never `doctrine:schema:update` — migrations are versioned in git, reviewed,
and replayable in order on any environment. That's why
[`docker/php/entrypoint.sh`](../docker/php/entrypoint.sh) can run
`doctrine:migrations:migrate` on every boot (for both the `app` and
`app_test` databases) and a fresh clone comes up fully schema'd.

### The repository

[`src/Repository/LinkRepository.php`](../src/Repository/LinkRepository.php)
is the *only* place that talks to the database — grep `src/` for
`createQueryBuilder` or `createQuery` and you'll find all of it here. The
controllers ask in domain terms (`findOneByCode`, `findAllNewestFirst`,
`incrementHits`, `remove`); what SQL that becomes is the repository's
business. Two details worth reading closely:

- `findAllNewestFirst()` orders by `createdAt DESC, id DESC` — the `id`
  tiebreaker exists because `DATETIME` has second precision, and
  [`tests/Api/ListLinksCest.php`](../tests/Api/ListLinksCest.php) creates
  three links within the same second.
- The queries are written in **DQL** (`l.code`, `App\Entity\Link`) — queries
  against the *object model*, which Doctrine translates to SQL against the
  *tables*. You can watch that translation happen (§5).

---

## 5. Parameterized queries, and how to watch them

The claim from the spec: SQL injection is a non-issue here. Not because
input is sanitized — nothing in this codebase escapes or sanitizes SQL
strings — but because **user input never becomes part of a SQL string at
all**.

Look at [`LinkRepository::findOneByCode`](../src/Repository/LinkRepository.php):

```php
->andWhere('l.code = :code')
->setParameter('code', $code)
```

The query text and the value travel to MySQL separately (a prepared
statement). The server compiles `SELECT ... WHERE code = ?` *first*, then
binds the value into the already-parsed statement. A code of
`'; DROP TABLE link; --` is just a 22-character string that matches no row —
it is structurally incapable of becoming syntax. The same pattern holds in
`incrementHits` (`WHERE l.code = :code`), and Doctrine's own INSERT/DELETE
for entities are parameterized the same way.

**Don't take the guide's word for it — watch it, in this project:**

1. **Tail Doctrine's query log** (dev env logs every statement with
   parameters shown separately, per
   [`config/packages/monolog.yaml`](../config/packages/monolog.yaml)):

   ```sh
   docker compose exec php sh -c 'tail -f var/log/dev.log | grep doctrine'
   # in another terminal:
   curl "http://localhost:8080/links/abc1234"
   # -> doctrine.DEBUG: Executing statement:
   #    SELECT ... FROM link l0_ WHERE l0_.code = ?
   #    (parameters: array{"1":"abc1234"}, ...)
   ```

   The `?` in the SQL and the value off to the side in `parameters:` — that
   separation is the whole defense.

2. **Print the SQL of any DQL query**: temporarily add
   `dump($query->getSQL());` before `getOneOrNullResult()` in
   `findOneByCode` — you'll see the generated
   `SELECT ... FROM link l0_ WHERE l0_.code = ?` with the placeholder intact.

3. **MySQL's general log** shows what the *server* received, including the
   `Execute` events with bound values. The exact commands are in the README
   section [“Peeking at the raw SQL”](../README.md#peeking-at-the-raw-sql).

Try the attack yourself, end to end:

```sh
curl -i "http://localhost:8080/links/%27%3B%20DROP%20TABLE%20link%3B%20--"
```

You'll get a 404 — and in this app it's a *routing* 404: the
`[0-9A-Za-z]+` requirement on the route (see
[`LinkController`](../src/Controller/LinkController.php)) rejects the path
before the database is even consulted. Defense in layers: the route regex
filters garbage, and even without it, the bound parameter makes injection
impossible.

---

## 6. REST conventions as used here

The full contract with curl examples is in the
[README's API reference](../README.md#api-reference); this is the *why*
behind the choices, each anchored in code:

- **Resource naming.** One resource, `links`, plural noun; the collection is
  `/links` and a member is `/links/{code}`. Verbs live in HTTP methods, not
  paths (no `/links/create`). The redirect `GET /r/{code}` is deliberately
  *outside* `/links` — it isn't a representation of the resource, it's the
  product feature, with different semantics (a 302, not JSON).
- **Status codes carry the semantics.**
  - `201 Created` + `Location` header on create
    ([`LinkController::create`](../src/Controller/LinkController.php)) —
    "made a thing, here's where it lives."
  - `400` vs `422`: malformed *request* vs well-formed request with invalid
    *values* — the two-step split at the top of `create()`, pinned by
    [`tests/Api/CreateLinkCest.php`](../tests/Api/CreateLinkCest.php).
  - `204 No Content` with an actually-empty body on delete
    ([`LinkController::delete`](../src/Controller/LinkController.php);
    [`tests/Api/DeleteLinkCest.php`](../tests/Api/DeleteLinkCest.php) asserts
    `'' === body`). A second delete of the same code is 404 — the operation
    is still safe to retry; only the *response* differs.
  - `302 Found`, not `301`, for the redirect
    ([`RedirectController`](../src/Controller/RedirectController.php)):
    browsers cache 301s aggressively and would skip the server on repeat
    visits — hit counting would silently break. The status code choice *is*
    a product decision.
  - `405` when the path exists but the verb doesn't — free, because routes
    declare `methods:`.
- **One error shape everywhere.** Every non-2xx/3xx response is
  `{"errors": [{"field": ..., "message": ...}]}` — validation failures,
  unknown codes, unknown routes, wrong methods, even unhandled 500s — because
  a single listener renders all of them
  ([`src/EventListener/JsonExceptionListener.php`](../src/EventListener/JsonExceptionListener.php)).
  A client needs exactly one error parser.
  [`tests/Api/CrossCuttingCest.php`](../tests/Api/CrossCuttingCest.php)
  proves the shape holds even off the happy routes.
- **Derived data isn't stored.** `shortUrl` is computed per-request from the
  route ([`LinkResponseMapper`](../src/Http/LinkResponseMapper.php));
  `createdAt` is RFC 3339 in UTC — an unambiguous wire format regardless of
  what the column type is.

---

## 7. Unit tests vs API tests — the pyramid, concretely

Two Codeception suites, split by a rule you can read at the top of each
suite config:

- [`tests/Unit.suite.yml`](../tests/Unit.suite.yml): *pure logic only, no
  kernel, no database.*
- [`tests/Api.suite.yml`](../tests/Api.suite.yml): *real HTTP semantics
  (status codes, headers, JSON bodies) against the Symfony kernel booted in
  the `test` env.*

**What earned a unit test** — logic with interesting branches and no
infrastructure:

- [`tests/Unit/CodeGeneratorTest.php`](../tests/Unit/CodeGeneratorTest.php):
  length is exactly 7, alphabet is exactly base62, consecutive calls differ
  (a sanity check, not a randomness proof — read the comment). Milliseconds
  to run, and it can loop 200 generations cheaply.
- [`tests/Unit/LinkCreatorTest.php`](../tests/Unit/LinkCreatorTest.php): the
  collision-retry loop, with the EntityManager **mocked** to throw the same
  `UniqueConstraintViolationException` real MySQL would. Forcing a real
  collision through the API would mean either 62^7 luck or fixture
  surgery; the mock makes "flush fails twice, then succeeds" and "flush
  fails five times" trivial to stage — including asserting `resetManager()`
  is called after each failure.

**What deliberately did *not* get a unit test** (the comment in
[`Unit.suite.yml`](../tests/Unit.suite.yml) says it out loud): controllers,
repository DQL, validator constraints. A unit test of `findOneByCode` would
mock the query builder and assert you called `andWhere` with the right string
— testing your mocks, not your query. Whether the DQL is right, whether the
constraint config rejects `ftp://`, whether the 404 renders in the error
shape — those are only real when the whole stack runs. So:

- [`tests/Api/CreateLinkCest.php`](../tests/Api/CreateLinkCest.php) — the
  201/422/400 matrix, response shape, `Location` header.
- [`tests/Api/RedirectCest.php`](../tests/Api/RedirectCest.php) — 302 +
  `Location`, and that hits *actually* increment (asserted back through the
  API, not by poking the DB).
- [`tests/Api/ListLinksCest.php`](../tests/Api/ListLinksCest.php),
  [`ShowLinkCest.php`](../tests/Api/ShowLinkCest.php),
  [`DeleteLinkCest.php`](../tests/Api/DeleteLinkCest.php),
  [`CrossCuttingCest.php`](../tests/Api/CrossCuttingCest.php) — the rest of
  the contract, including the non-ASCII-code 404s.

Mechanics worth knowing: the API suite's Doctrine module (`cleanup: true` in
[`Api.suite.yml`](../tests/Api.suite.yml)) wraps each test in a transaction
rolled back afterwards — isolation without truncating tables — and it runs
against the separate `app_test` database (created by
[`docker/mysql/init/01-create-test-db.sql`](../docker/mysql/init/01-create-test-db.sql),
selected via the `dbname_suffix` in
[`config/packages/doctrine.yaml`](../config/packages/doctrine.yaml)'s
`when@test` block), so dev data is never touched. Shared helpers like
`haveLink()` live on the actor,
[`tests/Support/ApiTester.php`](../tests/Support/ApiTester.php).

Run them:

```sh
docker compose exec php php vendor/bin/codecept run          # everything
docker compose exec php php vendor/bin/codecept run Unit     # ~instant
docker compose exec php php vendor/bin/codecept run Api      # boots kernel + DB
```

The pyramid shape follows from cost: unit tests are so cheap you can afford
hundreds; API tests each boot the kernel and touch MySQL, so each one has to
earn its place by pinning a piece of the *contract*.

---

## 8. Questions to sit with

Work through these with the code open. Each has a concrete answer in a file
named above; the quiz will echo them.

1. A request comes in for `GET /nope`. Which container, file, and event turn
   that into a JSON 404 — and why does the *same* file produce the JSON for a
   422 validation failure? (§1.1, §1.10)
2. Why does `POST /links` with `{"url": 123}` return 400 while
   `{"url": "not a url"}` returns 422? Where exactly is the line drawn in
   [`LinkController::create`](../src/Controller/LinkController.php), and what
   would go wrong if the `is_string` guard were removed? (§1.5)
3. `LinkCreator` never checks whether a code is taken before inserting. Walk
   through the interleaving of two concurrent requests that makes a
   check-then-insert version lose — and explain what the unique index does
   differently. (§1.7)
4. Nothing in `src/` ever writes `new LinkRepository(...)` — yet every
   controller gets one. Trace the chain from
   [`config/services.yaml`](../config/services.yaml) to the constructor
   argument. What breaks if two services implement the same interface? (§3)
5. At which line of [`LinkCreator::create`](../src/Service/LinkCreator.php)
   does the `INSERT` actually reach MySQL — and how does `$link->getId()` go
   from `null` to a number without anyone assigning it? (§4)
6. Why is `incrementHits` a single DQL `UPDATE ... SET l.hits = l.hits + 1`
   instead of loading the entity, doing `$hits + 1` in PHP, and flushing?
   Describe the exact lost-update sequence the naive version allows. (§4,
   [`LinkRepository`](../src/Repository/LinkRepository.php))
7. Someone POSTs `'; DROP TABLE link; --` as a *code lookup*. List the two
   independent layers in this project that make it harmless, and name the
   command you'd run to see the bound parameter with your own eyes. (§5, §6)
8. Why 302 and not 301 for `/r/{code}`? What user-visible metric silently
   breaks under 301, and why? (§6,
   [`RedirectController`](../src/Controller/RedirectController.php))
9. The repository's `findOneByCode` has no test in `tests/Unit/`, and
   `CodeGenerator` has no test in `tests/Api/`. Justify both absences in one
   sentence each. (§7)
10. `createdAt` ordering alone isn't enough for `GET /links` — why does
    [`findAllNewestFirst`](../src/Repository/LinkRepository.php) also order
    by `id DESC`, and which API test would (occasionally!) fail without it?
    (§4, [`tests/Api/ListLinksCest.php`](../tests/Api/ListLinksCest.php))
11. Delete a link, then DELETE it again: 404. Is that a violation of
    idempotency? What's the difference between an idempotent *operation* and
    an identical *response*? (§6)
12. If you renamed the `url` column to `target_url` on the entity, list every
    step until the running containers and the test database both have the new
    schema — without ever running `doctrine:schema:update`. (§4,
    [`docker/php/entrypoint.sh`](../docker/php/entrypoint.sh))
