# Part 2 — Exercises

Curriculum step 5: **you implement, Claude reviews.** Nine modification tasks
against the finished task tracker, ordered easy → hard. Each one extends
something that already exists, so the first move is always the same: read the
file(s) named in the exercise and the tests that pin their current behavior.

The rules, same as the rest of part 2:

- Everything runs inside the `php` container
  (`docker compose exec php vendor/bin/codecept run`, `.../php-cs-fixer fix`,
  `.../phpstan analyse`). Nothing on the host.
- **Definition of done is a green Jenkins build** (`http://localhost:8082`,
  *task-tracker → Build Now*). The pipeline judges the working tree, so you
  don't need to commit to get a verdict. Locally that means: both Codeception
  suites green, zero PHP-CS-Fixer drift, zero PHPStan level-8 errors.
- Contract changes (new endpoints, params, matrix rows) update `README.md`
  as part of the exercise — the README's API tables and permission matrix are
  documentation *of record*, and stale docs fail review.
- When you're done with an exercise, ask for a review and name the exercise.
  Reviews check the acceptance criteria, the test quality, and whether the
  change stayed consistent with the codebase's conventions (thin controllers,
  DTO + Validator, the single error shape, voters not inline ifs).

Paths below are relative to `task-tracker/`.

---

## 1. A new sort field through the whitelist: `sort=updatedAt`

**Task.** Allow `GET /api/teams/{id}/tasks?sort=updatedAt`. The `Task` entity
already maintains `updatedAt` (every setter calls `touch()`); it just isn't
sortable. Wire it through the whitelist exactly the way `createdAt` is.

**Why it teaches:** the refactored read path was *designed* for this change —
you get to feel how small a feature is when the seam is right, and how a sort
whitelist (never interpolating user input into ORDER BY) is extended safely.

**Acceptance criteria**

- `TaskListQuery::SORT_FIELDS` and `DoctrineTaskListProvider::SORT_DQL` each
  gain one entry; the 422 message for a bad `sort` names the new field too.
- No cache changes needed — and you can say *why* (`sort` already
  participates in `TaskCacheKeys::taskList()`).
- Tests:
  - `tests/Unit/TaskListQueryTest.php`: `updatedAt` parses as valid;
    the rejection table in `testInvalidValuesThrowNamingTheParameter()`
    still passes unchanged.
  - `tests/Api/TaskListCest.php`: a new test proving `sort=updatedAt` orders
    by *edit* time — e.g. create task A then B, then PATCH A, and assert
    `sort=updatedAt&direction=desc` puts A first while
    `sort=createdAt&direction=desc` puts B first.
- `README.md` pagination table lists the new sort value.

<details>
<summary>Hints</summary>

- The two production edits are one line each: `'updatedAt'` in
  `SORT_FIELDS`, `'updatedAt' => 't.updatedAt'` in `SORT_DQL`. The message in
  the `InvalidTaskListQuery` throw for `sort` is the third edit.
- The Api test is the actual work. `updatedAt` is **server-controlled**
  (unlike `dueDate`, which `dueDateSortOrdersByDate` can simply pass in), and
  the DATETIME columns have **second** precision — create A, create B, PATCH A
  all land in the same second, so `updatedAt` ties and the `t.id` tiebreak
  decides, failing your assertion. Two honest options:
  1. `sleep(1)` between creating B and patching A, with a comment saying why
     (one second of suite time buys determinism); or
  2. set `updatedAt` directly via Doctrine (`grabEntityFromRepository` +
     reflection, since `touch()` is private) — more machinery, no sleep.
  Either is fine; say which you picked and why. This is precisely the
  reason the provider adds `->addOrderBy('t.id', ...)`.
- If you go the Doctrine-direct route, remember the lesson of
  `CacheCest::cacheHitsServeFromRedisWithoutQueryingMysql`: a behind-the-back
  write does **not** invalidate the cache. Do the sneaky edit *before* the
  first `GET` for that query shape.
</details>

---

## 2. Red team your own dashboard: the `|raw` experiment

**Task.** Deliberately break the XSS mitigation, watch the test catch it,
then put it back. In `templates/dashboard/team.html.twig` change
`{{ comment.body }}` to `{{ comment.body|raw }}`, run
`DashboardCest:storedXssPayloadsRenderInert`, and study the failure. Then
revert and get back to green. Nothing from this exercise survives in the tree.

**Why it teaches:** you see exactly what Twig autoescaping buys — and that
neither PHPStan nor PHP-CS-Fixer says a word about `|raw`; only a behavioral
test stands between you and stored XSS.

**Acceptance criteria**

- You ran `docker compose exec php vendor/bin/codecept run Api DashboardCest:storedXssPayloadsRenderInert`
  with the `|raw` in place and can state, in the review, **which assertion
  failed first and why** (there are four; the payload `<img src=x
  onerror=alert(1)>` trips two of them — escaped form absent, live markup
  present).
- Optional but recommended: reproduce it in a real browser against the dev
  stack (store the payload via curl per the README auth walkthrough, log in
  at `http://localhost:8081/login`, open the team dashboard) and observe in
  devtools that even the *live* `<img onerror=...>` does not execute —
  name the response header responsible (layer 2, `SecurityHeadersListener`).
- Optional: trigger *Build Now* while broken and watch the pipeline go red at
  the Codeception stage — the gate catches what no reviewer was shown.
- `git status` clean for `templates/` afterwards; full Api suite green again.

<details>
<summary>Hints</summary>

- `git restore templates/dashboard/team.html.twig` is the cleanest revert.
- When reasoning about the browser demo: the CSP is `default-src 'none'`
  with no `unsafe-inline`, so inline event handlers are dead even when the
  markup is live. The test proves escaping; the header is the backstop for
  the day someone ships a `|raw`.
- Stretch (a real technique, small effort): add a policy guard so the *next*
  `|raw` can't land silently — a tiny Unit test that reads every file under
  `templates/` and asserts the string `|raw` appears nowhere. Crude, greppy,
  and exactly how teams pin "we don't do this here" decisions. If you add
  it, keep it: it's the one artifact of this exercise allowed to survive.
</details>

---

## 3. A new Jenkins stage: `composer audit`

**Task.** Add a supply-chain gate to the pipeline: a stage that runs
`composer audit` and red-fails the build when any installed package has a
known security advisory.

**Why it teaches:** extending a scripted pipeline hands-on, plus the one
quality gate part 2's pipeline is missing — dependencies with published CVEs.

**Acceptance criteria**

- A new stage in `Jenkinsfile` (sensible position: right after
  `composer validate + install`, before style/static — cheapest checks
  first is the existing order's logic).
- *Build Now* shows the stage in Console Output and the build is green.
- You ran the same command manually first
  (`docker compose exec php composer audit`) and can say what data source
  the advisories come from.
- `README.md` CI section: the stage list gains the new stage.

<details>
<summary>Hints</summary>

- The `PHP` helper string at the top of the `Jenkinsfile` is how every other
  stage runs a one-off command in the app image — `sh "${PHP} composer audit"`
  is the whole stage body. Placing it after the install stage means `vendor/`
  and `composer.lock` are both present in the CI workspace.
- No Jenkins restart, no seed-job rerun: the bootstrap `load`s the
  `Jenkinsfile` fresh from the bind-mounted repo on every build. Edit, Build
  Now, verdict.
- Composer ≥ 2.8 also reports **abandoned** packages, and its default mode
  fails the command on them — that would make your stage red for a reason
  that isn't a vulnerability. Decide explicitly: `--abandoned=fail` (strict,
  may bite the day a dev dependency is abandoned) or `--abandoned=report`
  (stage stays about CVEs only). Either is defensible; an *undecided default*
  is not. Check `composer audit --help` inside the container for what your
  Composer version does.
- There is no easy safe way to demo the red path (that would need a
  deliberately vulnerable requirement in `composer.lock`); it's enough to
  explain in review what output and exit code an advisory would produce.
</details>

---

## 4. A new filter through the whole read path: `priority`

**Task.** Add `?priority=low|medium|high` to `GET /api/teams/{id}/tasks`,
modeled on the existing `status` filter — including the part that is easy to
forget: the **cache key**.

**Why it teaches:** a filter is not done when the SQL is right. Every knob on
a cached read must participate in the cache key, or two different queries
collide on one Redis entry and serve each other's rows.

**Acceptance criteria**

- `TaskListQuery`: new readonly field, parsed via `TaskPriority::tryFrom()`
  with a loud 422 naming `priority` on anything off-contract, plus a
  `priorityValue(): ?string` canonical form alongside `statusValue()`.
- `DoctrineTaskListProvider`: parameterized `andWhere`, same style as status.
- `TaskCacheKeys::taskList()`: signature and shape string extended.
  `CachingTaskListProvider` passes the new value. (PHPStan level 8 will hunt
  down every call site for you — let it.)
- Tests:
  - `tests/Unit/TaskCacheKeysTest.php`: `priority` joins the "every parameter
    participates in the key" variants, **and** a no-bleed case in the spirit
    of `testValuesCannotBleedAcrossFieldPositions()`.
  - `tests/Unit/TaskListQueryTest.php`: valid parse, default (absent → null),
    and `priority` rows in the rejection table (`'urgent'`, `'HIGH'`, `''`).
  - `tests/Api/TaskListCest.php`: the filter narrows results (mirror
    `statusAndAssigneeFiltersNarrowTheList`), and a `priority` case in
    `offContractParamsAre422NeverSilentFallback`.
- `README.md` pagination/filtering table gains the row.

**Suggested order (the point of the exercise):** write the
`TaskCacheKeysTest` participation case *first* and watch it fail against the
unchanged key builder — that failing test is the collision bug you are
preventing, made visible before it can exist.

<details>
<summary>Hints</summary>

- `status` is your template at every layer: `TaskListQuery::fromRequest()`
  (the `tryFrom` + throw block), the provider's `andWhere`, the
  `statusValue()` canonical form, the fixed-order shape string in
  `TaskCacheKeys`.
- Keep the shape-string field order fixed and documented — position is what
  `testValuesCannotBleedAcrossFieldPositions()` protects. Append `priority`
  at the end; the sha1 changes for every existing key, which just means a
  cold cache once, not a correctness problem (the comment in
  `docs/solid-refactor.md` about *not* cold-starting was about preserving
  behavior during a pure refactor — this is a contract change, different
  rules).
- `CacheCest::distinctQueryShapesGetDistinctEntries` is worth extending with
  a priority variant if you want end-to-end proof against real Redis, but
  the unit-level key test is the required one.
</details>

---

## 5. Rate limit the reads: a third limiter plus a burst demo

**Task.** GET endpoints under `/api` are currently unmetered — a client can
hammer `GET /api/teams/{id}/tasks` all day (Redis absorbs most of it, MySQL
eats every cache miss). Add an `api_read` limiter: per-user, sliding window,
its own budget, enforced by a third hook on `RateLimitListener`. Then extend
the demo tooling so the limit is demonstrable with a scripted burst, like the
auth limiter already is.

**Why it teaches:** you re-derive the listener's central design decision —
*which side of the firewall, keyed by what* — for a new case, instead of
just reading the answer in a comment.

**Acceptance criteria**

- `config/packages/rate_limiter.yaml`: an `api_read` limiter (sliding
  window, `cache.rate_limiter` pool), its limit a parameter with a
  `when@test` override of 10000 like the other two. Pick a dev limit and
  justify it (reads should be meaningfully looser than writes; a small
  number keeps the demo fast).
- `RateLimitListener`: a third `#[AsEventListener]` hook for GET (and HEAD —
  decide and say why) under `/api`, keyed per user, **after** the firewall.
  The existing two hooks and their priorities untouched.
- Tests, `tests/Unit/RateLimitListenerTest.php`:
  - constructor plumbing updated (the listener now takes three factories);
  - read over the limit → `TooManyRequestsHttpException` with Retry-After;
  - reads keyed per user, not per IP;
  - reads do not consume the write budget and vice versa (the existing
    `testReadsAreNeverWriteLimited` keeps passing — reads still don't touch
    the *write* limiter; its name stays honest).
- A burst script — either a new `scripts/read-rate-limit-demo.sh` or a
  clearly separated section — that against the dev stack shows: N × 200,
  then 429 with `Retry-After` in the standard error shape. Recovery is
  optional (it's a 60s wait; the auth demo already proves the pattern).
- `README.md`: the limiter table gains a row; the demo script is mentioned.

<details>
<summary>Hints</summary>

- Autowiring convention: the constructor parameter *name* selects the
  limiter — `$authLimiter` ↔ the `auth` limiter, `$apiWriteLimiter` ↔
  `api_write`. So `RateLimiterFactoryInterface $apiReadLimiter` binds
  `api_read` with zero config.
- Priority: reads need the authenticated user, so this hook lives at
  priority 4 with `onApiWrite`, and the same "no user can only mean the
  firewall already 401'd it" reasoning applies — read that comment block in
  `onApiWrite()` until you can reproduce the argument, then cite it in your
  own hook's comment. One subtlety: `onApiWrite` needed an auth-paths
  carve-out to avoid double-charging `POST /api/tokens`; check whether your
  read hook needs one (are any `AUTH_PATHS` GETs? what about
  `GET /api/tokens`, which is a plain authenticated read?).
- The burst script needs a *valid* token, unlike `rate-limit-demo.sh`, which
  deliberately uses bad credentials and mutates nothing. Two consequences:
  1. setup does `POST /api/register` + `POST /api/tokens` — that consumes 2
     of the 5/min *auth* budget, so keep the read demo a **separate script**
     (running it right after the auth demo would 429 on setup);
  2. registration mutates the dev DB, so use a unique email per run
     (`read-demo-$(date +%s)@example.com`) or the second run dies on the
     duplicate-email 422.
- `GET /api/me` is the cheapest read to hammer. Loop with curl exactly like
  `attempt()` does in the existing script — copy its header-scraping awk.
</details>

---

## 6. One more SOLID step: a logging decorator on the read path

**Task.** `docs/solid-refactor.md` closes with "a metrics/logging concern is
another decorator in `services.yaml`." Prove it. Write
`src/Task/LoggingTaskListProvider.php` — same `TaskListProviderInterface`,
logs one structured line per list (team id, page, sort, filters, duration in
ms, result total) — and wire it **outside** the caching decorator so it times
cache hits and misses alike. Zero edits to the controller or either existing
provider.

**Why it teaches:** decorator stacking is where OCP/DIP stop being a diagram —
a whole cross-cutting concern lands as one new class plus config, and
Symfony's `decoration_priority` decides who wraps whom.

**Acceptance criteria**

- New class, `LoggerInterface` + inner provider injected; no other
  dependencies. `TaskController`, `DoctrineTaskListProvider`,
  `CachingTaskListProvider`, and `TaskListProviderInterface` untouched.
- `config/services.yaml`: the new decorator wired so the chain is
  Logging → Caching → Doctrine, with a comment explaining the priority
  arithmetic (this is the part reviews will push on).
- Empirical proof of the order: two identical dev-stack requests to
  `GET /api/teams/{id}/tasks`, and the two log lines from
  `var/log/dev.log` quoted in your review notes — the second (cache hit)
  visibly faster, which is only observable if logging wraps caching.
- Test: `tests/Unit/LoggingTaskListProviderTest.php` — stub inner provider
  returning a fixed payload, Monolog `TestHandler` as the logger; assert the
  inner result passes through unmodified and the record carries the expected
  context fields.
- Full suite + pipeline green (the Api suite must not care that logging
  exists — that indifference is the point).

<details>
<summary>Hints</summary>

- Both decorators can declare `decorates: App\Task\DoctrineTaskListProvider`.
  With multiple decorators on one subject, **higher `decoration_priority` is
  applied first**, and applied-first means closest to the decorated service.
  `CachingTaskListProvider` has the default priority 0, so give logging a
  *negative* priority (e.g. `-10`): it's applied last and ends up outermost.
  Verify empirically rather than trusting the sentence — that's what the
  two-request log check is for.
- Timing: `hrtime(true)` before/after `$this->inner->list(...)`, divide by
  1e6 for ms. Log at `info` with a context array, not string interpolation —
  structured context is what makes log lines queryable.
- The query's loggable form: `TaskListQuery`'s public readonly fields plus
  `statusValue()`/`assigneeValue()` give you everything; don't invent a
  serializer.
- Monolog's `TestHandler` ships with `monolog/monolog` (already installed):
  `new Logger('test', [$handler = new TestHandler()])`, then
  `$handler->getRecords()`.
</details>

---

## 7. A new voter-guarded operation: archive a task

**Task.** Tasks pile up; deleting is destructive. Add archiving:
`POST /api/tasks/{id}/archive` and `POST /api/tasks/{id}/unarchive`, guarded
by a new `TaskVoter::ARCHIVE` attribute (proposed rule: creator or team
admin, same as `TASK_DELETE` — archiving is soft moderation; defend or amend
in review). Archived tasks disappear from the default task list; a new
whitelisted `archived` query param brings them back.

**Why it teaches:** the full vertical for a new operation — migration, entity,
voter attribute, endpoints, list semantics, cache key *and* invalidation,
permission matrix, tests at every layer. Exercise 4's cache-key lesson
compounds here.

**Acceptance criteria**

- Migration adding `archived` (bool, default false) to `task`; generated
  inside the container, applied to **both** dev and test databases (the Api
  suite runs against `app_test` — schema drift there fails everything).
- `Task`: field + accessors following the entity's existing style
  (mutators call `touch()`); the task payload in `ApiResponseMapper`
  includes `archived`.
- `TaskVoter::ARCHIVE` in `supports()`/`voteOnAttribute()`; both endpoints
  deny with it; both are write paths, so both **invalidate** via
  `TeamTasksCacheInvalidator` (they change what cached lists contain).
- List contract: default excludes archived; `archived=true` returns only
  archived; anything else off-contract is a 422 naming `archived`. The param
  goes through `TaskListQuery` and — the compounding part — through
  `TaskCacheKeys::taskList()`.
- A written decision (one paragraph in the README or a code comment):
  do archived tasks still count in the dashboard summary
  (`TeamSummaryProvider` / `TaskRepository::countByStatus`)? Either answer
  is fine; an unconsidered one is not.
- Tests:
  - `tests/Unit/TaskVoterTest.php`: ARCHIVE rows — creator yes, admin yes,
    other member no, outsider no (model: `testDeleteIsCreatorOrAdmin`).
  - `tests/Unit/TaskListQueryTest.php` + `TaskCacheKeysTest.php`: the new
    param parses, rejects loudly, and participates in the key.
  - Api (new `ArchiveCest` or extended `TaskCest`): member archives own
    task; member gets 403 on someone else's; admin archives anyone's;
    outsider 404; archiving makes the task vanish from the default list
    **immediately** (that's the invalidation assertion); `archived=true`
    shows it; unarchive restores it.
- `README.md`: matrix row, endpoint rows, filter-table row.

<details>
<summary>Hints</summary>

- Migration: `docker compose exec php bin/console make:migration` diffs the
  entities against the schema (maker-bundle is installed). Read the
  generated SQL before trusting it. Then either run
  `doctrine:migrations:migrate` twice (plain and `--env=test`) or just
  `docker compose restart php` — the entrypoint migrates both databases on
  every start.
- Existing rows: the column default handles backfill; every current task is
  active, so no existing test should notice the list-contract change. If one
  does, that's information.
- Controller shape: two small actions in `TaskController` using
  `taskOr404()` + `denyAccessUnlessGranted(TaskVoter::ARCHIVE, $task)`,
  returning the mapped task with 200 — mirror `delete()`'s invalidation
  placement (after the flush, per `TeamTasksCacheInvalidator`'s class
  comment).
- Provider filter: parameterize it
  (`->andWhere('t.archived = :archived')->setParameter(...)`) rather than
  interpolating a literal — consistent with everything else there.
- Contract detail worth deciding consciously: is `archived=false` accepted
  (explicit form of the default) or 422? Accepting `'true'`/`'false'` and
  defaulting to false is the friendlier contract; whatever you pick, the
  rejection table pins it.
</details>

---

## 8. Admin handoff: a transfer flow the matrix can't express today

**Task.** The last-admin invariant means a sole admin can never demote
themself (`PATCH .../members/{userId}` returns 422) — so handing a team off
currently takes two calls and only works if you promote the other person
first. Add a single atomic operation:
`POST /api/teams/{id}/transfer-admin` with body `{"userId": N}` — the caller
(an admin) makes the target member an admin and steps down to member, in one
flush. Update the permission matrix.

**Why it teaches:** where business rules live versus where authorization
lives (MemberController's class comment draws exactly this line), plus
atomicity and the per-request memoization trap in `TeamMembershipResolver`.

**Acceptance criteria**

- New DTO (`TransferAdminRequest` or similar) following the existing
  `fromArray` + Validator pattern; malformed body → the standard 400/422
  shapes.
- Authorization by voter, not inline ifs: reuse
  `TeamVoter::MANAGE_MEMBERS`, or introduce a dedicated attribute — state
  which and why (if new: `TeamVoterTest` rows for it).
- Contract, each case tested in a new Api cest (or extended `MemberCest`):
  - outsider → 404 (the `teamOr404` policy);
  - plain member → 403;
  - admin, target is a member → 200; `GET .../members` then shows target as
    `admin` and caller as `member`;
  - target is the caller → 422 (transferring to yourself is a no-op wearing
    a trench coat);
  - target not in the team → 404 (consistent with `membershipOr404`);
  - **the motivating case**: a sole admin transfers successfully — the very
    state where `changeRole` still (correctly) refuses to demote them.
  - target already an admin: pick a contract (allow — postcondition already
    half-holds, caller just steps down — or 422), document and test it.
- Both role changes in **one** flush; no interleaving where the team has
  zero admins even transiently in the unit of work.
- `TeamMembershipResolver::forget()` called for both users (precedent:
  `changeRole` and `remove` — the memo would otherwise serve the pre-transfer
  role to any voter check later in the same request).
- `README.md`: matrix updated (this is a new row/footnote in "Manage
  members"), endpoint table row added.

<details>
<summary>Hints</summary>

- `MemberController` is the home: it already has `teamOr404`,
  `membershipOr404`, the mapper, and the resolver. The transfer action is
  ~25 lines in its style.
- Atomicity here means: mutate both `TeamMembership` entities, then one
  `$em->flush()`. The last-admin guard needs no special case — the
  postcondition (target is admin) satisfies the invariant by construction,
  which is worth saying in a comment.
- Fetch the *caller's* membership via
  `$this->memberships->findOneByUserAndTeam($user, $team)` — it must exist
  (the `teamOr404` already proved membership), so an `assert` is in keeping
  with the codebase's style.
- Route naming: the codebase's routes are resource-shaped, and this is an
  action — `transfer-admin` as a verb-path is the pragmatic exception. If
  that itches, `POST /api/teams/{id}/admin` (replace the admin) is the
  RESTful spelling; either passes review with a sentence of justification.
- 422 vs 403 for self-transfer: the caller is *allowed* to transfer (they're
  an admin); this particular request is invalid. That's the same reasoning
  the last-admin guard documents — 422, `ApiProblem::validationError()`.
</details>

---

## 9. A second cached read: the member list, invalidated correctly

**Task.** Cache `GET /api/teams/{id}/members` in Redis (key
`team_members.{teamId}`, its own tag, TTL parameter) and invalidate it on
**every** write path that changes what it returns. You enumerate those paths
yourself — that enumeration *is* the exercise.

**Why it teaches:** the caching pattern is easy; the discipline is the
enumeration ("the write path you forget is the bug" — `TeamTasksCacheInvalidator`'s
class comment). Bonus lesson: where caching must stop — the member *list* is
display data, but `TeamMembershipResolver` feeds the voters, and authorization
data does not belong in a cross-request cache.

**Acceptance criteria**

- Read-through on the `tasks.cache` pool, modeled on `TeamSummaryProvider`
  (single key per team, no query shapes — no decorator needed; a small
  `TeamMembersProvider`-style class keeps `MemberController::list()` thin).
- A **new tag**, not `team_tasks.{teamId}` — task writes must not wipe the
  member list, nor member writes the task lists. Key/tag builders live with
  the others (in `TaskCacheKeys` or a renamed/general home — naming pressure
  is real here; address it, don't ignore it). TTL as a parameter in
  `config/packages/cache.yaml` beside the other two.
- Invalidation on: member add, role change, member removal/leave, team
  delete (which now invalidates **both** tags) — and, if you built
  exercise 8, the transfer endpoint. If you did build 8 and forgot it,
  that's the lesson demonstrating itself; the test below must catch it.
- A one-paragraph comment (on the provider or the invalidator) stating why
  `TeamMembershipResolver` remains uncached: per-request memoization only,
  because a Redis-cached membership would let a demoted admin keep admin
  powers for up to a TTL.
- Tests:
  - Unit: key/tag builder coverage in the style of `TaskCacheKeysTest`
    (scheme, determinism, team isolation, and that the new tag differs from
    `team_tasks.{teamId}`).
  - Api, `CacheCest`-style (new `MemberCacheCest` or extended `CacheCest`):
    - a read populates the pool (assert with `grabServiceTyped` +
      `getItem(...)->isHit()`, like `taskListReadPopulatesTheRedisCache`);
    - each write path makes the very next read fresh — cover **all** of your
      enumerated paths, one assertion each (this is the test that catches a
      forgotten path);
    - the behind-the-back proof: `haveInRepository(new TeamMembership(...))`
      directly via Doctrine, then show the cached list is stale — proof the
      cache really serves reads (model:
      `cacheHitsServeFromRedisWithoutQueryingMysql`);
    - a task write does *not* wipe the member list, and a member write does
      not wipe the task list (the tag-separation assertion).
- `README.md`: the cached-reads table gains a row; the invalidation
  paragraph mentions the second tag.

<details>
<summary>Hints</summary>

- `TeamSummaryProvider` is the exact template: `TagAwareCacheInterface`,
  `#[Autowire('%...ttl%')]`, `get(key, function (ItemInterface $item) {...})`
  with `expiresAfter` + `tag`. Your payload is the already-mapped
  `array_map($this->mapper->member(...), ...)` result — cache the array, not
  entities.
- Invalidator shape: `TeamTasksCacheInvalidator` is named for its tag. Either
  add a sibling (`TeamMembersCacheInvalidator`) or generalize — but
  remember `TeamController::delete()` must now hit both, and whichever
  design makes *forgetting that* hardest is the better one. Say which you
  chose and why in review.
- Cache-vs-transaction isolation: the `CacheCest` header comment explains
  why these tests don't bleed into each other — Redis lives outside the
  per-test DB rollback, but keys embed team ids and auto-increment ids are
  never reused. Your keys inherit that property for free; understand it
  before relying on it.
- For the behind-the-back membership: grab the `Team` and a fresh `User` via
  `grabEntityFromRepository` (see how `cacheHitsServeFromRedisWithoutQueryingMysql`
  builds its sneaky `Task`), construct `new TeamMembership($user, $team,
  TeamRole::Member)`, `haveInRepository(...)`.
- Staleness framing for the review: what's the worst a 300s-stale member
  list can show? A removed member still listed — cosmetic, because every
  *authorization* decision still goes through the uncached resolver. That
  asymmetry is the whole reason this read is cacheable at all.
</details>

---

## When you finish

Each exercise ends the same way: local suites green, fixer clean, PHPStan
clean, *Build Now* green, README honest. Then ask for the review, naming the
exercise number and flagging any place you deviated from the acceptance
criteria on purpose — a justified deviation is a conversation; a silent one
is a finding.
