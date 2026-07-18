# Part 1 quiz — why was it built this way?

Fifteen questions about the link-shortener you just built. Almost none of them
ask *what* the code does — you can read that. They ask *why* it was built this
way, and what would break if it weren't. Answering usually means having the
referenced file open.

Answers with explanations are in [quiz-answers.md](quiz-answers.md). Try to
write your answer down (a sentence or two is enough) before looking — the
questions are designed so that a vague answer *feels* right until you have to
commit to it.

---

## Request lifecycle

**1.** `docker/nginx/default.conf` rewrites every request that isn't an
existing file to `public/index.php` — `/links`, `/r/aZ3kQ9x`, even
`/definitely/not/a/route` all execute the same nine-line PHP file. Classic PHP
worked the other way: nginx mapped each URL path to its own `.php` file.

Trace what happens between nginx receiving `GET /links/aZ3kQ9x` and
`LinkController::show()` running — name the pieces in order, and where the
URL-to-method mapping is declared. Then: name two concrete features of *this
app* that only work because every request flows through the one front
controller, and would fall apart under the one-file-per-URL model.

**2.** A `NotFoundHttpException` thrown in `LinkController::show()` comes out
of the API as `{"errors": [{"field": null, "message": "Link not found."}]}`
with status 404 — but no controller ever builds that JSON.

What mechanism turns the thrown exception into that response
(`src/EventListener/JsonExceptionListener.php` is the destination — the
question is how execution gets there)? Why is this an event listener rather
than a `try/catch` in each controller action? And why is the listener
registered at priority `-8` specifically — what must run before it, and what
must it run before?

## Dependency injection

**3.** `LinkController::create()` receives a `LinkCreator` from the container.
Suppose you "simplified" it instead:

```php
$creator = new LinkCreator(new CodeGenerator(), /* ??? */);
```

Work through why this is worse, starting with the literal problem: what do you
put for `???`? (Look at `LinkCreator`'s constructor.) Then find at least two
more distinct problems — one of them is visible in
`tests/Unit/LinkCreatorTest.php`.

**4.** Nothing in `config/services.yaml` mentions `LinkCreator`,
`CodeGenerator`, `LinkRepository`, or `LinkResponseMapper` by name, yet the
container constructs and injects all of them, with their own dependencies
filled in. Which two settings in that file make this work, and what
information does the container actually use to decide what to pass to
`LinkCreator::__construct()`?

**5.** In `LinkController`, `LinkRepository` and `LinkResponseMapper` are
injected through the constructor, but `ValidatorInterface` and `LinkCreator`
are injected as arguments of the `create()` action only. Both styles work —
so what's the reasoning for splitting them this way in this controller? When
would you choose each?

## Doctrine

**6.** The schema is created by `migrations/Version20260718201115.php`, run at
container boot — and the README insists: "always migrations, never
`doctrine:schema:update`". Both commands can produce the same `CREATE TABLE`.
What does the migration workflow protect you from that `schema:update
--force` doesn't? Give a concrete example, using this app's `link` table, of
how `schema:update` could silently destroy data after an entity refactor.

**7.** In `LinkCreator::create()`, both `$em->persist($link)` and
`$em->flush()` are called. What does each actually do, and at which of the two
calls does the `INSERT` statement reach MySQL? Explain why the
`try/catch (UniqueConstraintViolationException)` could never catch anything if
only `persist()` were inside the `try` — and then why the catch block has to
call `$this->registry->resetManager()` before the loop retries.

**8.** `LinkRepository::incrementHits()` executes one DQL statement:

```php
'UPDATE App\Entity\Link l SET l.hits = l.hits + 1 WHERE l.code = :code'
```

The "natural" ORM version — load the entity, `$link->setHits($link->getHits()
+ 1)`, flush — was deliberately not used. Write out the exact interleaving of
two concurrent `GET /r/{code}` requests under which the natural version
records one hit instead of two, and explain why the single-statement version
cannot lose a hit that way.

**9.** `LinkCreator` claims a code by *inserting and catching*
`UniqueConstraintViolationException`, rather than the intuitive approach:
`SELECT` first to check the code is free, then insert. The intuitive version
passes every test in this repo. Why is it still a bug? Describe the failure
sequence, and name the one thing in this system that can actually guarantee
code uniqueness (say where it's declared, and where it's created).

## SQL injection

**10.** `LinkRepository::findOneByCode()` builds its query like this:

```php
$this->createQueryBuilder('l')
    ->andWhere('l.code = :code')
    ->setParameter('code', $code)
```

Explain precisely why a hostile `$code` such as `'; DROP TABLE link; --`
cannot alter this query — what does MySQL receive, in how many parts, and why
can the value never be parsed as SQL? Then write the one-line rewrite of this
query builder chain that *would* be injectable, and name the fastest way in
this project to *see* (not trust) that the real query is parameterized. (The
README's "Peeking at the raw SQL" section is the cheat sheet — the question is
whether you can explain what the `?` in the logged query proves.)

**11.** The `{code}` routes also carry `requirements: ['code' =>
'[0-9A-Za-z]+']`, which happens to reject every character an injection payload
needs. So is that regex the app's SQL-injection defense? If parameterization
already makes injection impossible, what problem does the regex actually
solve — and what would a request like `GET /r/héllo` do without it? (See the
comment above `RedirectController::__invoke()` and the entity's `code` column
definition.)

## REST choices

**12.** `POST /links` distinguishes three outcomes: `201` on success, `400`
for a malformed body, `422` for an invalid URL. Why `201` rather than plain
`200` — and what is the `Location` header for? Then defend the `400`/`422`
split: what different *cause* does each signal to a client, and why does
`{"url": 123}` land on the `400` side of the line rather than `422`? (See the
two-step comment structure in `LinkController::create()` and
`CreateLinkCest::numericUrlValueIs400()` — what worse status code is that
test protecting against?)

**13.** `GET /r/{code}` returns `302 Found`, yet a short link is permanent —
`301 Moved Permanently` is the semantically "correct" code. Why would using
`301` silently break a core feature of this product? Which client behavior
causes it?

**14.** `DELETE /links/{code}` returns `204` with an empty body, and deleting
the same code a *second* time returns `404`
(`DeleteLinkCest::secondDeleteOfTheSameCodeIs404`). Some APIs return `204` for
both calls. What does each choice communicate to a client, and in what sense
is this DELETE still idempotent even though the two calls get different status
codes?

## Test pyramid

**15.** This suite draws the unit/API line like so: `CodeGenerator` and
`LinkCreator` get unit tests (`tests/Unit/`), while every endpoint behavior —
including hit counting — is asserted only through HTTP (`tests/Api/`).

- (a) What property of `CodeGenerator` makes it the ideal unit-test target,
  and why would unit-testing `RedirectController` instead be low-value?
- (b) The collision-retry logic in `LinkCreator` is tested with a mocked
  `EntityManagerInterface` that throws `UniqueConstraintViolationException`
  on demand. Why can this behavior *not* practically be tested through the
  API — what would you have to control to trigger a real collision?
- (c) `tests/Api.suite.yml` sets the Doctrine module's `cleanup: true` and the
  suite runs in the `test` env against the `app_test` database. What does
  each of those two choices buy, and which specific test in
  `ListLinksCest` would break without cleanup?
