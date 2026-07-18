# Part 1 — Exercises

Seven modification tasks against the finished link-shortener, ordered easy to
hard. You implement, Claude reviews (curriculum step 5). Each exercise says
what to build, why it's worth building, and what "done" looks like — including
the tests you add. Hints are collapsed; try without them first.

## How to work

- One branch per exercise: `git switch -c exercise/1-expires-at`.
- Run the full suite before you start (it must be green) and before you ask
  for review:

  ```sh
  docker compose exec php php vendor/bin/codecept run
  ```

- Schema changes go through Doctrine Migrations, never
  `doctrine:schema:update`. The loop:

  ```sh
  docker compose exec php php bin/console make:migration        # generate a diff
  # review + edit the generated file in migrations/ — always
  docker compose exec php php bin/console doctrine:migrations:migrate
  docker compose exec php php bin/console doctrine:migrations:migrate --env=test
  ```

  The `--env=test` run matters: the Api suite talks to `app_test`, and a
  migration you only applied to `app` will fail there with an unknown-column
  error. (A container restart also re-runs both — see
  `docker/php/entrypoint.sh` — but learn the manual commands.)

- New Cest classes in `tests/Api/` are picked up automatically; you only need
  `vendor/bin/codecept build` if you change a `tests/*.suite.yml`.
- When an exercise changes the public API, updating the API reference in
  `README.md` is part of the exercise. That habit is the point.

Stay inside part-1 scope: no auth, no Redis, no caching, no pagination.

---

## 1. Add `expiresAt` to links

**Teaches:** the full lifecycle of a schema change — entity, reviewed
migration, DTO validation, response mapper — in one thin slice.

### Task

Links get an optional expiry timestamp. `POST /links` accepts an optional
`"expiresAt"` field (RFC 3339 / ATOM string, like `createdAt` already uses);
it is stored on the entity as a nullable datetime and returned in every Link
resource (`"expiresAt": null` when unset). No behavior changes yet — an
expired link still redirects. Exercise 3 builds on this.

### Acceptance criteria

- [ ] `App\Entity\Link` has a nullable `expiresAt` datetime field; the new
      migration in `migrations/` was generated with `make:migration`, reviewed
      by hand, has a working `down()`, and is applied to both `app` and
      `app_test`.
- [ ] `POST /links` with `"expiresAt": "2027-01-01T00:00:00+00:00"` returns
      201 and echoes the value back; without the field (or with `null`) the
      link never expires and responses show `"expiresAt": null`.
- [ ] A garbage value (`"expiresAt": "tomorrow-ish"`) is a 422 with
      `field: "expiresAt"` — same error shape as the `url` violations.
- [ ] Stored and returned in UTC, like `createdAt`.
- [ ] Decide whether a past `expiresAt` is accepted, and write the decision
      down in a code comment. Recommendation: allow it (a link created
      already-expired is silly but harmless) — this makes exercise 3 testable
      purely through the API.
- [ ] Tests: extend `tests/Api/CreateLinkCest.php` with the three cases above
      (round-trip, absent-means-null, garbage-is-422). Existing suites stay
      green. `README.md` Link resource example updated.

<details>
<summary>Hints</summary>

- Entity: mirror `createdAt` —
  `#[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]` on a
  `private ?\DateTimeImmutable $expiresAt = null;`, plus an optional
  constructor parameter. `make:migration` diffs the entity metadata against
  the live schema; expect `ALTER TABLE link ADD expires_at DATETIME DEFAULT
  NULL`.
- DTO: add `public readonly ?string $expiresAt = null` to
  `App\Dto\CreateLinkRequest` with
  `#[Assert\DateTime(format: \DateTimeInterface::ATOM)]`. Validator
  constraints skip `null`, so optional falls out for free — and violations
  automatically carry `field: "expiresAt"` through the existing loop in
  `LinkController::create()`.
- Controller: after validation passes, convert with
  `\DateTimeImmutable::createFromFormat(\DATE_ATOM, $dto->expiresAt)` and
  normalize with `->setTimezone(new \DateTimeZone('UTC'))` — the column is a
  `DATETIME`, it stores no timezone, so the convention "always UTC" is on
  you (see the comment on `Link::$createdAt`).
- Mapper: `App\Http\LinkResponseMapper::toArray()` gains
  `'expiresAt' => $link->getExpiresAt()?->format(\DATE_ATOM)` — and update
  its `@return array{...}` docblock while you're there.
- Pitfall: forgetting `--env=test` on the migrate. The Api suite will tell
  you loudly.

</details>

---

## 2. Top-N popular links endpoint

**Teaches:** repository/QueryBuilder practice — plus a routing lesson hiding
in plain sight: `top` is a perfectly valid short code.

### Task

`GET /links/top?limit=N` returns the N most-hit links in the usual
`{"links": [...]}` shape, ordered by hits descending. Default limit 5,
maximum 100; an unusable `limit` (not a positive integer, or over the cap) is
rejected with the standard error shape. The query lives in
`App\Repository\LinkRepository`, not in the controller.

### Acceptance criteria

- [ ] `GET /links/top` is a 200 with at most 5 links, most hits first; ties
      are broken deterministically (document how, in a comment).
- [ ] `?limit=2` returns 2; `?limit=0`, `?limit=abc`, `?limit=101` are
      rejected — pick 400 or 422, justify the choice against the API's
      existing 400-vs-422 split (see `README.md` "Cross-cutting"), and pin it
      in a test.
- [ ] The route is **not** shadowed: `GET /links/top` reaches your new action
      even though `top` matches the `[0-9A-Za-z]+` requirement on
      `link_show`. (Corollary to note in a comment: a link whose code is
      literally `top` is now unreachable via `GET /links/{code}`. Exercise 6
      deals with reserving it.)
- [ ] New repository method (e.g. `findTopByHits(int $limit): array`)
      following the house style of `findAllNewestFirst()`.
- [ ] Tests: new `tests/Api/TopLinksCest.php` — seed three links with
      `$I->haveLink()`, give them different hit counts via `/r/{code}` (with
      `$I->stopFollowingRedirects()`, like `tests/Api/RedirectCest.php`),
      assert order and limit; assert the invalid-limit case; and one test
      whose whole job is `GET /links/top` returning 200 rather than the
      `link_show` 404 — that test is your regression guard against route
      shadowing. `README.md` gains the endpoint.

<details>
<summary>Hints</summary>

- The gotcha first: attribute routes register in method-declaration order, so
  if your `top()` method sits below `show()` in `LinkController`, the router
  matches `link_show` first and you get a confusing
  `{"errors":[{"field":null,"message":"Link not found."}]}`. Two fixes:
  declare the method above `show()`, or give the route
  `#[Route('/top', name: 'link_top', priority: 1, methods: ['GET'])]`.
  Diagnose this class of problem with
  `docker compose exec php php bin/console router:match /links/top --method=GET`
  — it names the exact route that wins.
- Query: `$this->createQueryBuilder('l')->orderBy('l.hits', 'DESC')
  ->addOrderBy('l.id', 'DESC')->setMaxResults($limit)` — the `id DESC`
  tiebreaker for the same reason `findAllNewestFirst()` has one.
- Reading the param: `$request->query->getInt('limit', 5)` is tempting but
  quietly turns `"abc"` into `0` — fine if you treat "< 1" as invalid, but
  know it's happening. Throwing `BadRequestHttpException` gets rendered by
  `App\EventListener\JsonExceptionListener` for free.

</details>

---

## 3. 410 Gone for expired links

**Teaches:** HTTP semantics beyond 404 — Gone means "existed, deliberately no
longer served" — and where time-based rules belong.

### Task

Builds on exercise 1. Once `expiresAt` is in the past, `GET /r/{code}`
returns **410 Gone** in the standard JSON error shape and does **not**
increment the hit count. The management API is unaffected: `GET
/links/{code}` still returns the resource (with its `expiresAt`), the link
still appears in `GET /links`, and `DELETE` still works.

### Acceptance criteria

- [ ] Expired link: `/r/{code}` → 410, error shape with `field: null` and a
      clear message (e.g. `"Link expired."`); a follow-up `GET /links/{code}`
      proves `hits` did not move.
- [ ] Unexpired and never-expiring links: 302 exactly as before
      (`tests/Api/RedirectCest.php` stays green).
- [ ] The 410 flows through `JsonExceptionListener` like every other error —
      no hand-built error response in the controller.
- [ ] Expiry comparison happens in UTC; the "is expired?" decision lives in
      one obvious place, not inline math in the controller.
- [ ] Tests: new `tests/Api/ExpiredLinkCest.php` — create a link with a past
      `expiresAt` (through the API, if you took exercise 1's recommendation),
      assert 410 + message + unchanged hits; a future `expiresAt` still 302s.
      No `sleep()` anywhere. `README.md` redirect table gains the 410 row.

<details>
<summary>Hints</summary>

- `Symfony\Component\HttpKernel\Exception\GoneHttpException` exists for
  exactly this; throw it from `RedirectController::__invoke()` before the
  `incrementHits()` call.
- Give the entity the predicate: `Link::isExpired(): bool` comparing
  `$this->expiresAt` against `new \DateTimeImmutable('now', new
  \DateTimeZone('UTC'))`, with `null` meaning never. The controller then
  reads as policy, not arithmetic.
- If you rejected past `expiresAt` values in exercise 1, you can't create an
  expired link over the API. Seed one directly instead:
  `$em = $I->grabService('doctrine.orm.entity_manager');` then persist and
  flush a hand-built `Link` — the Doctrine module's per-test transaction
  (see `tests/Api.suite.yml`, `cleanup: true`) rolls it back afterwards.
- Order of checks: expired beats not-found only in the sense that the row
  still exists — an unknown code stays 404. Make sure your test suite covers
  both.

</details>

---

## 4. Bulk-create console command

**Teaches:** the same services, wired into a CLI entry point instead of a
controller — Symfony Console plus DI outside HTTP.

### Task

A console command `app:links:bulk-create <file>` reads URLs from a file (one
per line), validates each line with the same rules as `POST /links` (reuse
`CreateLinkRequest`), creates links through `App\Service\LinkCreator`, and
prints a result table (code, short URL, target URL). Blank lines are skipped.
Invalid lines don't abort the run: they're reported with their line number,
and the command exits non-zero if any line failed, zero otherwise.

### Acceptance criteria

- [ ] `docker compose exec php php bin/console app:links:bulk-create
      urls.txt` creates every valid URL and prints the table;
      `bin/console list` shows the command with a description.
- [ ] Validation is the DTO's, not a re-implementation: inject
      `ValidatorInterface`, validate `new CreateLinkRequest($line)`.
- [ ] Exit code 0 when all lines succeed, 1 when any fail; failures reported
      per line ("line 3: This value is not a valid URL.").
- [ ] The command class is registered purely by autoconfiguration (no
      `services.yaml` edits).
- [ ] Tests: new `tests/Api/BulkCreateCommandCest.php` (the Api suite boots
      the kernel, which is what a command test needs). Use
      `$I->runSymfonyConsoleCommand(...)` with a fixture file the test writes
      itself; then assert through `GET /links` that the links exist. One test
      for the mixed valid/invalid file asserting the exit code and that valid
      lines were still created.

<details>
<summary>Hints</summary>

- Scaffold: `docker compose exec php php bin/console make:command
  app:links:bulk-create` (maker-bundle is installed). You get a class with
  `#[AsCommand(...)]` — that attribute plus `autoconfigure: true` in
  `config/services.yaml` is the entire registration story.
- Constructor-inject `LinkCreator` and `ValidatorInterface` exactly as
  `LinkController` does; commands are ordinary services.
- `SymfonyStyle` (`$io = new SymfonyStyle($input, $output)`) gives you
  `$io->table(...)` and `$io->error(...)` for free. Return
  `Command::SUCCESS` / `Command::FAILURE`.
- Short URLs in CLI: there is no HTTP request, so
  `UrlGeneratorInterface::ABSOLUTE_URL` falls back to the `DEFAULT_URI` env
  var (see `.env`) — you'll print `http://localhost/r/...` without the
  `:8080`. Either set `DEFAULT_URI=http://localhost:8080` or accept it;
  say which in a comment. This is worth understanding, not just fixing.
- In the test, `Codeception\Module\Symfony::runSymfonyConsoleCommand('app:links:bulk-create',
  ['file' => $path])` runs against the same kernel and DB connection as the
  rest of the suite, so the Doctrine module's rollback still isolates the
  test. Write the fixture with `tempnam(sys_get_temp_dir(), 'urls')`; pass
  `expectedExitCode: 1` for the failure case.

</details>

---

## 5. Reject self-referential URLs with a custom constraint

**Teaches:** the Validator's main extension point — a Constraint +
ConstraintValidator pair — and that validators are services with injectable
dependencies.

### Task

`POST /links` must refuse to shorten a URL that points back at the shortener
itself (shortening `http://localhost:8080/r/abc1234` invites redirect
chains). Implement it as a custom constraint attribute (e.g.
`#[NotSelfReferential]`) on `CreateLinkRequest::$url`, producing an ordinary
422 with `field: "url"`. Rule: reject when the submitted URL's host (and
port, if present) equals the host of the request doing the creating. When
there is no current HTTP request — exercise 4's console command validates the
same DTO — the check passes.

### Acceptance criteria

- [ ] New constraint + validator classes under `src/Validator/` (new
      directory), autowired with no `services.yaml` edits.
- [ ] `POST /links` with `"url": "http://localhost/r/abc1234"` → 422,
      `field: "url"`, a purpose-written message (pin the exact message in a
      test). Note: in the Api suite the in-process client's host is
      `localhost`, no port — that's the self host to use in tests.
- [ ] `https://example.com/r/abc1234` (same path shape, foreign host) is
      still a 201 — the rule is about the host, not the path.
- [ ] `vendor/bin/codecept run Api BulkCreateCommandCest` (exercise 4) still
      passes — proof of the no-request escape hatch. If you skipped exercise
      4, cover it another way and say how.
- [ ] Tests: the two API cases above added to `tests/Api/CreateLinkCest.php`;
      all existing url-validation tests untouched and green.

<details>
<summary>Hints</summary>

- Two classes by convention: `NotSelfReferential` extends
  `Symfony\Component\Validator\Constraint`, carries
  `#[\Attribute(\Attribute::TARGET_PROPERTY)]` and a public `$message`;
  `NotSelfReferentialValidator` extends `ConstraintValidator`. The naming is
  load-bearing — `Constraint::validatedBy()` defaults to the constraint's
  class name + `Validator`.
- The validator takes `RequestStack` in its constructor — autowiring handles
  it because `src/` classes are all services. `getMainRequest()` returns
  `null` in a console context: that's your escape hatch, return early.
- Compare hosts with `parse_url($value, PHP_URL_HOST)` /
  `PHP_URL_PORT` against `$request->getHost()` / `$request->getPort()`
  (careful: default ports — decide whether `http://localhost:80` equals
  `http://localhost`, and test whichever you decide).
- Report with
  `$this->context->buildViolation($constraint->message)->addViolation();`
  — the property path (`url`) is attached automatically because the
  constraint sits on the property, which is why the existing error-mapping
  loop in `LinkController::create()` needs no changes.
- Empty/invalid values: let `NotBlank`/`Url` own those. A custom validator
  conventionally returns early on `null === $value || '' === $value`.

</details>

---

## 6. Custom short codes

**Teaches:** API design under a uniqueness constraint — a new status code
(409), a widening migration, and the difference between a retryable collision
and a non-retryable one.

### Task

`POST /links` accepts an optional `"code"`: 4–32 base62 characters, used
verbatim. Rules:

- Format violations (too short, too long, non-base62) → 422, `field: "code"`.
- Reserved words are rejected with 422: at minimum `top` if you built
  exercise 2 (otherwise pick a plausible reserved list and comment why).
- Code already taken → **409 Conflict** in the standard error shape.
- No `"code"` supplied → generated 7-char codes, exactly as today, including
  the collision retry in `LinkCreator`.
- A custom code that collides must **not** be retried — retrying the same
  string five times is busywork. One attempt, then 409.

The `link.code` column is `VARCHAR(7)`; custom codes up to 32 chars need a
migration.

### Acceptance criteria

- [ ] Migration widens `code` to `VARCHAR(32)`, keeps the `ascii`
      charset / `ascii_bin` collation and the `uniq_link_code` index (read
      `migrations/Version20260718201115.php` for what those are for), has a
      `down()`, and is applied to both databases.
- [ ] `POST /links` with a valid custom code returns 201, the body's `code`
      is the supplied one, `shortUrl` ends `/r/{that-code}`, and
      `GET /r/{that-code}` 302s.
- [ ] Same code twice → second request is 409. Whether the 409 body carries
      `field: "code"` or `field: null` is your call — argue it briefly
      against the `field` convention in `README.md`, then pin it in a test.
- [ ] `App\Service\LinkCreator::create()` grew an optional code parameter;
      generated-code behavior (loop, `MAX_ATTEMPTS`, `CodeCollisionException`)
      is unchanged.
- [ ] Tests: API — round-trip, duplicate→409, bad format→422, reserved→422,
      redirect works (spread across `CreateLinkCest` or a new
      `CustomCodeCest`). Unit — extend `tests/Unit/LinkCreatorTest.php`: with
      a supplied code, a unique-violation flush is **not** retried (`persist`
      and `flush` called exactly once, and the failure surfaces); reuse its
      `uniqueViolation()` helper.
- [ ] `README.md` POST /links section documents the field, the 409, and the
      reserved words.

<details>
<summary>Hints</summary>

- Entity + migration: change `#[ORM\Column(length: 7, ...)]` to
  `length: 32` on `Link::$code`, regenerate, and check the generated SQL
  kept `CHARACTER SET ascii ... COLLATE ascii_bin` — Doctrine's diff
  occasionally drops column options; the migration file is yours to correct.
  This is why the workflow says "review by hand".
- DTO format rule:
  `#[Assert\Regex(pattern: '/^[0-9A-Za-z]{4,32}$/', message: '...')]` on a
  new nullable `$code` property. For the reserved list, either a
  `#[Assert\Callback]` method on the DTO or — now that exercise 5 taught you
  how — a tiny `ReservedCode` custom constraint. Both produce
  `field: "code"` violations through the existing loop.
- Why 4 minimum? So a custom code can never be confused with route
  collisions like `top` at other lengths... it can (`top` is 3 chars, but
  `tops` is 4). The real answer is the reserved list, and the minimum is
  just taste — but notice the route-shadowing interaction from exercise 2
  applies to any future literal segment under `/links/`.
- `LinkCreator`: `create(string $url, ?string $code = null)`. Custom path:
  single persist/flush in a try/catch; on
  `UniqueConstraintViolationException`, call
  `$this->registry->resetManager()` (a failed flush closes the
  EntityManager — same reason the retry loop does it) and throw something
  the controller can map, e.g. a new `App\Service\CodeTakenException`.
- Controller: catch it and either throw
  `Symfony\Component\HttpKernel\Exception\ConflictHttpException` (renders
  via `JsonExceptionListener`, `field: null`) or return a `JsonResponse`
  with `field: "code"` yourself. That's the trade-off to argue.

</details>

---

## 7. Prove the hit counter is concurrency-safe

**Teaches:** turning a code-review claim into an executable proof — the
lost-update anomaly, and why a single SQL statement is immune to it.

### Task

`LinkRepository::incrementHits()` is a single
`UPDATE link SET hits = hits + 1` precisely so that concurrent redirects
never lose a count — its docblock claims read-modify-write "would be a lost
update waiting to happen". Nothing in the test suite proves that. Two
deliverables:

1. **A deterministic test** that demonstrates the anomaly and its absence:
   using two independent database connections against a committed row,
   interleave the naive pattern (A reads 0, B reads 0, A writes 1, B writes
   1 → final hits 1: an update was lost) and then run the atomic pattern on
   both connections (final hits 2). Assert both outcomes.
2. **A real-concurrency check from the host**, documented (commands + pasted
   output) in a short comment block at the top of the new test file: create
   a link, fire ~100 parallel redirects at the running stack, and show
   `hits` equals exactly 100.

### Acceptance criteria

- [ ] New `tests/Api/HitCounterConcurrencyCest.php` (the suite runs inside
      the php container, where MySQL is reachable). It manages its own row:
      the Doctrine module's rollback (see `tests/Api.suite.yml`) only covers
      the kernel's connection, so your raw connections must insert a
      committed row and delete it in `_after()` — which must clean up even
      when assertions fail.
- [ ] The naive interleave provably ends at 1; the atomic interleave at 2.
      No sleeps, no retries, no flakiness — this test is fully
      deterministic.
- [ ] A comment in the test explains, in your own words, *why* the
      single-threaded interleave is a faithful model of the race, and what
      the atomic statement removes (the gap between read and write).
- [ ] The host-side hammer output shows `"hits": 100` for 100 parallel
      requests against `http://localhost:8080`.
- [ ] Everything else stays green.

<details>
<summary>Hints</summary>

- Two connections, no framework needed — plain PDO, same as
  `docker/php/entrypoint.sh` uses:
  `new \PDO('mysql:host=mysql;dbname=app_test', 'app', 'app')`, twice. PDO
  autocommit is on by default, which is exactly what you want: every
  statement commits, both connections see each other's writes, and nothing
  blocks.
- The naive interleave is four statements:
  `A: SELECT hits` → `B: SELECT hits` (both see 0) →
  `A: UPDATE ... SET hits = 0+1` (bind the value you read) →
  `B: UPDATE ... SET hits = 0+1`. Final row: 1. Two increments happened;
  one is gone. The atomic version — `UPDATE link SET hits = hits + 1` on A,
  then on B — has no read-write gap to interleave into, so it lands on 2 no
  matter the ordering. That impossibility of interleaving *is* the proof.
- Row bookkeeping: `code` is `VARCHAR(7)` (unless exercise 6 widened it), so
  use a fixed 7-char marker like `raceXX1`; `DELETE` it before inserting
  (belt and braces against a previous crashed run) and in `_after()`.
  `created_at` is NOT NULL — supply `UTC_TIMESTAMP()`.
- Host hammer (dev database, so clean up after):

  ```sh
  CODE=$(curl -s -X POST http://localhost:8080/links \
    -H 'Content-Type: application/json' \
    -d '{"url":"https://example.com/hammer"}' | sed -E 's/.*"code":"([^"]+)".*/\1/')
  for i in $(seq 1 100); do curl -s -o /dev/null "http://localhost:8080/r/$CODE" & done; wait
  curl -s "http://localhost:8080/links/$CODE"   # expect "hits": 100
  curl -s -X DELETE "http://localhost:8080/links/$CODE" -o /dev/null
  ```

  This one exercises real php-fpm worker concurrency. If `incrementHits()`
  were read-modify-write, this is where you'd see 100 shrink.
- Honesty check worth including in your comment: the Cest proves the *SQL
  pattern* is safe, and the hammer observes the *system* behaving safely,
  but neither would automatically fail if someone rewrote
  `incrementHits()` as entity read-modify-write — catching that
  deterministically needs two competing EntityManagers, which is beyond
  this exercise. Naming a test's blind spot is part of writing good tests.

</details>

---

## When you're done with an exercise

Run the whole suite, skim your diff as if reviewing a stranger's PR, then
ask Claude for a review. Expect questions of the form "why this status
code?", "what happens under concurrency?", and "which suite should this test
live in?" — the same questions the quiz (curriculum step 6) will ask.
