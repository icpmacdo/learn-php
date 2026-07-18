# Part 2 quiz — answers

Questions are in [quiz.md](quiz.md). File paths are relative to
`task-tracker/`.

---

## Authentication vs. authorization

### A1. Where each lives, and why the split

**Authentication** lives in the firewall layer, configured in
`config/packages/security.yaml`: the `api` firewall (stateless,
`access_token` with `App\Security\ApiTokenHandler` resolving Bearer tokens)
and the `web` firewall (`form_login` + session cookie). By the time a
controller runs, authentication is *finished* — `$this->getUser()` is either
a `User` or the request never got this far. The `access_control` rules
(`^/api → ROLE_USER`, `^/dashboard → ROLE_USER`) are the coarse "must be
logged in at all" gate, also enforced before controllers.

**Authorization** lives in the voters —
`src/Security/Voter/{TaskVoter,TeamVoter,CommentVoter}.php` — invoked from
controllers via `denyAccessUnlessGranted()` / `isGranted()`, all three
delegating the membership lookup to `App\Security\TeamMembershipResolver`.

Why split there: authentication is a property of the *request* (which
firewall matched, what credentials it carried) and is identical for every
endpoint behind that firewall — so it belongs in one piece of
infrastructure, not repeated per action. Authorization is a property of the
*(user, subject, action)* triple — "may Alice delete task 9" — which cannot
be decided until the controller has loaded task 9. Checking both at the top
of each action would mean every controller re-implements token parsing and
session handling (and gets one of them subtly wrong), while the permission
rules would still need the loaded entity anyway. Symfony's shape gives each
concern the earliest point where it has the data it needs.

### A2. A Bearer token at the dashboard door

Response: **302 redirect to `/login`** — the request is treated as fully
anonymous. Proven by `DashboardCest::anApiTokenAloneDoesNotOpenTheDashboard()`.

Why: firewalls are matched by request pattern, and each firewall only speaks
its own authentication scheme. `/dashboard` matches the `web` firewall
(`security.yaml` — the `api` firewall's pattern is `^/api`), and the `web`
firewall's only authenticator is `form_login` backed by a session. It never
looks at the `Authorization` header, so the token is not *invalid* — it is
simply not part of any conversation this firewall knows how to have. The
anonymous user then hits `^/dashboard → ROLE_USER` in `access_control`, and
the web firewall's "please authenticate" is a redirect to the login form
(where the API firewall's equivalent, `App\Security\ApiAuthenticationEntryPoint`,
is a JSON 401). Two firewalls, two auth worlds, no leakage between them.

### A3. Public rows first

They must be public because they are the doors *into* authentication: you
cannot present a token you have not been issued (`POST /api/tokens` is the
credential-for-token exchange) and you cannot log in to an account that does
not exist (`POST /api/register`). Requiring `ROLE_USER` on them would be
circular.

They must come first because — as the comment in `security.yaml` says —
**only the first matching `access_control` rule applies**. `^/api/register$`
and `^/api/tokens$` both also match `^/api`; flipped, the `^/api → ROLE_USER`
rule would win and every registration or token request would be met by
`ApiAuthenticationEntryPoint`'s `401 Authentication required.` — a service
nobody can ever bootstrap into.

## Voters

### A4. The inline version and its three costs

Without the voter, `TaskController::delete()` needs roughly:

```php
$membership = $membershipRepo->findOneByUserAndTeam($user, $task->getTeam());
if ($membership === null) { /* not even a member */ throw ...; }
if ($membership->getRole() !== TeamRole::Admin
    && $task->getCreatedBy()->getUserIdentifier() !== $user->getUserIdentifier()) {
    throw new AccessDeniedException();
}
```

Three concrete costs avoided:

1. **The permission matrix stops existing as a single artifact.** The PRD §4
   matrix (view/edit/comment = member; delete = creator or admin) currently
   reads straight out of `TaskVoter`'s `match` — one screen, one file. Inline,
   the same policy would be smeared across `TaskController`,
   `CommentController` (which *also* checks `TaskVoter::VIEW` and
   `TaskVoter::COMMENT` for comments riding on tasks), and
   `DashboardController::teamOr404()`. A policy change ("admins may edit any
   task") becomes a grep-and-hope across controllers instead of one `match`
   arm.
2. **The policy stops being unit-testable.** `tests/Unit/TaskVoterTest.php`
   drives every matrix cell as pure PHP — in-memory entities, no kernel, no
   DB, milliseconds. Inline ifs can only be exercised through full HTTP tests
   per endpoint per role.
3. **Duplication invites drift and N+1 queries.** Each inline copy would
   hand-roll the membership lookup; `TeamMembershipResolver` exists precisely
   so the lookup lives once and is memoized per request (the view gate and
   the action check in a single call must not mean two identical SELECTs).
   Two controllers with slightly different inline checks *will* eventually
   disagree — and the one that is wrong is the security hole.

Bonus symmetry: the controller line reads as *what* is required
(`TaskVoter::DELETE` on `$task`), and the voter owns *who* satisfies it.

### A5. Comparing users by identifier

`TaskVoter`'s comment says it directly: the voter unit tests build entities
**in memory**, where no entity has been persisted — so `getId()` is `null`
for everyone. Comparing ids would make two *different* users compare equal
(`null === null`), and the "is this the creator" test would silently pass
for the wrong user — the test suite would be lying about the security rule.
Object identity (`===` on the entities) fails in the other direction: it is
true only for the same PHP object, which holds inside one Doctrine unit of
work but is an accident of the identity map, not a domain fact. The email
via `getUserIdentifier()` is the domain's actual unique identifier
(`User.email` is the provider property in `security.yaml`), true in memory
and in the database alike. It breaks *first* in
`tests/Unit/TaskVoterTest.php` — which is exactly why the tests were built
that way: they force the voter to compare identity the honest way.

### A6. Business rules vs. authorization rules

The distinction: an **authorization rule** answers "is this caller allowed
to perform this *category* of action on this subject?" — it depends only on
who you are relative to the subject (member? admin? author?). A **business
rule** constrains *which states the domain may enter*, regardless of who is
acting: "a team must keep at least one admin" is false even when the caller
is unambiguously allowed to manage members. That is why the last-admin
violation is a **422 validation error** (`ApiProblem::validationError(...)`
— "you may do this kind of thing, this particular change is invalid") and
not a 403 ("you may not do this kind of thing").

File placement follows: category-of-action rules go in the voter
(`TeamVoter::MANAGE_MEMBERS` = admin only), state rules go in the controller
next to the other validations (`MemberController::changeRole()/remove()`
checking `countAdmins($team) <= 1`). Self-removal is the same lens applied
to scope: "leaving is not managing" — removing yourself is a different
*action* than managing members, so `MemberController::remove()` only asks
the voter when `$userId !== $user->getId()`. Pushing these into the voter
would force it to answer questions ("is this the last admin?", "is the
target the caller?") that have nothing to do with the caller's role — and
would turn a validation failure into a misleading 403.

### A7. The memo and the `forget()`

The memo solves a query-amplification problem: one API request routinely
asks several voter questions about the same (user, team) pair —
`taskOr404()` checks `TASK_VIEW`, then the action checks `TASK_EDIT` or
`TASK_DELETE`; the dashboard asks per team per row. Without memoization each
`isGranted()` is another identical `SELECT` on `team_membership`. The memo
(`TeamMembershipResolver::$memo`, keyed `identifier|teamId`) makes the
lookup once per pair per request.

The bug `forget()` prevents: a memo is a per-request cache of a fact the
same request can *change*. In `changeRole()`, the `teamOr404()` VIEW check
has already memoized the target's old role by the time the role is flushed;
any voter check later in that same request would read the stale memo and see
the pre-change role — e.g. treat a just-demoted admin as still an admin.
`forget()` drops exactly that key after the flush, so post-change checks hit
the database and see the truth. (Same call in `remove()`.)

## API tokens

### A8. Token life, and the two whys

Life of a token (`TokenController::issue()` → `ApiTokenHandler`):

1. Credentials are verified (see A9), then
   `$plainToken = 'tt2_'.bin2hex(random_bytes(32))` — 256 bits from a
   CSPRNG behind a recognizable prefix.
2. `new ApiToken($user, hash('sha256', $plainToken), $dto->name)` — **only
   the SHA-256 hex digest** is persisted, into the `token_hash` column with
   unique index `uniq_api_token_hash`. The plain token appears in the 201
   response body and never exists server-side again.
3. On each request, `ApiTokenHandler::getUserBadgeFrom()` regex-checks the
   shape (`tt2_[0-9a-f]{64}`), computes `hash('sha256', $accessToken)`, and
   looks the digest up via `findOneByHash()` against the unique index. Any
   miss — malformed, unknown, revoked-and-deleted — is the same
   `BadCredentialsException`, rendered as one generic 401 by
   `App\Security\ApiAuthenticationFailureHandler`. A hit touches
   `lastUsedAt` and hands the already-loaded user to the authenticator via
   the `UserBadge` closure (no second provider query).

**(a) If stored plain:** the table becomes a credential store in cleartext.
The attack is **database leak → immediate account takeover**: any SQL
injection, stolen backup, misconfigured replica, or over-shouldered
`SELECT * FROM api_token` yields ready-to-use Bearer tokens for every user —
strictly worse than a password-hash leak, because there is nothing left to
crack and the tokens work until individually revoked. Hashed, the same leak
yields 64-char digests that cannot be replayed (the handler hashes whatever
the client presents; presenting a digest just gets hashed again and misses).

**(b) Why SHA-256 here and bcrypt/argon wrong:** the `ApiToken` docblock
carries the argument. Security: slow hashers exist to tax brute-force over
*low-entropy* inputs — human passwords. This input is a 256-bit random
value; enumerating 2^256 preimages is not a thing, so the slowness would buy
zero security while costing an argon verification on **every API request**.
Mechanics: bcrypt/argon are salted and non-deterministic — the same token
hashes differently every time — so there is nothing stable to index. Lookup
would degenerate into "load candidate rows and `password_verify` each",
which for tokens (no email to narrow by, the token *is* the identifier)
means scanning the table. Deterministic SHA-256 makes the digest itself the
indexed key: `findOneByHash()` is one unique-index hit. Passwords keep the
slow hasher; tokens need the fast deterministic one — same table-leak
threat, opposite entropy, opposite tool.

### A9. Enumeration oracle vs. helpful 422

The difference is the *audience* and what the disclosure is worth to them.
`POST /api/tokens` is `PUBLIC_ACCESS` and reachable by anyone on the
internet; if "unknown email" and "wrong password" were distinguishable, the
endpoint becomes a **user-enumeration oracle** — an anonymous attacker can
harvest which emails have accounts (feeding credential stuffing and
phishing) without ever logging in. Hence the deliberately identical
`401 Invalid credentials.` for both branches, noted in the controller
comment.

`MemberController::add()` sits behind two locked doors: the caller is
authenticated *and* has already passed `TeamVoter::MANAGE_MEMBERS` — they
are a team admin typing a colleague's address, per the comment in the file.
The information is low-value to that audience and high-value as UX (an
admin needs to know "typo" vs. "colleague hasn't registered"). Security
responses are priced per-endpoint by who can reach them, not applied as a
blanket rule.

## 401 vs. 403 vs. 404

### A10. Three requests, three codes

1. **401** — the request never authenticated. The `api` firewall finds no
   usable credentials, and `App\Security\ApiAuthenticationEntryPoint::start()`
   returns the standard-shape JSON 401 with `WWW-Authenticate: Bearer`.
   (An *invalid* token gets the same 401 from
   `ApiAuthenticationFailureHandler` — deliberately indistinguishable.)
   Meaning: "I don't know who you are."
2. **403** — `TaskController::delete()`: `taskOr404()` passes (the member
   *can* view the task), then
   `$this->denyAccessUnlessGranted(TaskVoter::DELETE, $task)` throws
   `AccessDeniedException` because `TaskVoter::DELETE` requires creator or
   admin. `App\EventListener\JsonExceptionListener` maps that exception
   (which is not an `HttpExceptionInterface`) to `403 Access denied.` in the
   one error shape. Meaning: "I know who you are; you may not do this."
3. **404** — `TaskController::taskOr404()`:

   ```php
   if ($task === null || !$this->isGranted(TaskVoter::VIEW, $task)) {
       throw new NotFoundHttpException('Task not found.');
   }
   ```

   A task the caller cannot view is answered *identically* to a task that
   does not exist.

Why 3 is not a 403: a 403 confirms **existence**. An attacker walking
`/api/tasks/1..N` (ids are sequential) would learn exactly which ids are
real, how many tasks other teams have, and could correlate growth over time
— resource-existence disclosure across tenant boundaries. The 404 policy
makes other teams' data *unaddressable*, not merely untouchable. Note the
ordering consequence spelled out in `JsonExceptionListener`: because every
cross-team probe 404s at the VIEW gate first, a real 403 only ever reaches
*members* of the team — so the 403 itself never leaks anything about other
teams. Same policy at `CommentController::commentOr404()`,
`DashboardController::teamOr404()` (proven by
`DashboardCest::nonMembersGetA404ForForeignTeamDashboards()`), and
`TokenController::revoke()`'s scoped lookup.

### A11. `isGranted` vs. `denyAccessUnlessGranted`

They differ in what failure they produce, and the two call sites need
*different* failures. `denyAccessUnlessGranted()` throws
`AccessDeniedException`, which the exception listener renders as **403** —
correct for action checks, where "you exist to me but may not do this" is
the intended answer. But for the visibility gate, a 403 is precisely the
leak A10 forbids. `isGranted()` just returns a bool, letting `taskOr404()`
fold "denied VIEW" and "no such row" into one
`NotFoundHttpException('Task not found.')`. Same voters, same policy data —
the call style is chosen by which HTTP story the failure must tell: 404 for
"does this exist *for you*", 403 for "may you do this to it".

## XSS — Twig vs. JSON

### A12. Why the dashboard is inert

**Primary: Twig output autoescaping.** Every `{{ ... }}` in
`templates/dashboard/team.html.twig` — `{{ task.title }}`,
`{{ comment.body }}`, `{{ team.name }}` — HTML-encodes on output, so the
stored title becomes `&lt;script&gt;alert('xss')&lt;/script&gt;`: visible as
text, dead as markup. The project-wide fact that keeps it airtight:
**no `|raw` filter anywhere** (grep the `templates/` tree — the
`DashboardController` docblock and the template comment both state it).
Autoescaping is only as strong as its absence of exceptions; `|raw` is the
per-site opt-out, and this project has zero. Note it is *escaping, not
filtering*: `DashboardCest::storedXssPayloadsRenderInert()` asserts the
payload is **present** as escaped text and **absent** as live markup — the
data is preserved byte-for-byte, only its interpretation is controlled.

**Second layer: the CSP from `App\EventListener\SecurityHeadersListener`.**
`default-src 'none'` means the browser may not load or execute script from
anywhere — no `<script>`, no `src`, no XHR — so even markup that somehow
survived escaping could not run (the `<img onerror>` payload additionally
has nowhere to fetch from and no event handler allowed to fire).
`frame-ancestors 'none'`/`X-Frame-Options: DENY` and
`X-Content-Type-Options: nosniff` round out the belt-and-suspenders.
Escaping prevents the injection; CSP caps the blast radius of any future
escaping mistake. Two independent layers, both asserted by
`DashboardCest::htmlResponsesCarrySecurityHeadersApiResponsesDoNot()`.

### A13. Why raw JSON is right — and where the reasoning ends

Escaping is a property of the **output context**, not of the data. In an
`application/json` document, `<script>` is just a string value — JSON
encoding already handles the characters that are dangerous *in JSON*
(quotes, backslashes), and a browser given `Content-Type: application/json`
does not execute it as a document. HTML-escaping the API payload would be
actively wrong: it would corrupt the data (`&lt;` is not what the user
wrote), push a presentation concern into storage, and break every non-HTML
consumer. That is also why `SecurityHeadersListener` skips `/api`: CSP and
frame directives govern how a *browser interprets a document*, and these
responses are data, not documents. Store raw, escape at the edge, per edge.

The reasoning stops exactly where the test comment in `DashboardCest` says
it does: the API is only "safe" because *its* context is JSON. The moment
any consumer interpolates `task.title` into the DOM via `innerHTML`, a
server-rendered page, an email template — the stored payload detonates in
*that* context. "We're just an API" quietly assumes every downstream
renderer will do its own contextual escaping; this project makes the point
concrete by being both producer and consumer: the same two strings travel
raw through `GET /api/tasks/{id}` and get escaped by Twig at
`/dashboard/teams/{id}`. Safety is a property each output edge must
re-earn, not something the storage layer settles once.

## Rate limiting

### A14. Two hooks, two keys, two sides of the firewall

**`onAuthEndpoint` — priority 16, before the firewall (8), keyed
`'ip:'.$clientIp`.** Key: on `/login`, `/api/register`, `/api/tokens` the
caller is by definition anonymous — there *is* no user id to key by; the
client IP is the only stable handle on an unauthenticated attacker. Side:
it must run before the firewall for two reasons from the docblock — on
`POST /login` the `form_login` authenticator handles the request and sets
the response itself, so a listener behind the firewall would never even see
the attempt; and running first is what makes **failed** attempts consume.

**`onApiWrite` — priority 4, after the firewall, keyed
`'user:'.$user->getId()`.** Key: per-user budgets isolate tenants — one user
hammering writes cannot exhaust anyone else's allowance, whereas an IP key
would lump everyone behind one office NAT into a shared bucket (one noisy
coworker rate-limits the whole floor). Side: the user id *only exists after
the firewall has authenticated the request*, so this hook must sit behind
it. The listener's comment works through why the "no user" early-return is
safe: any unauthenticated `/api` write was already stopped at priority 8
with a 401 (the firewall halts event propagation), except the
`PUBLIC_ACCESS` auth endpoints — which the `AUTH_PATHS` check already
returned for (they were charged the stricter auth budget at 16; charging
twice would be unfair).

**The bypass prevented:** if the auth limiter ran after the firewall,
failed logins would never be counted — `form_login` short-circuits the
request before lower-priority listeners run. An attacker could then hammer
`POST /login` with a password list at full speed, unmetered:
**credential stuffing**, the exact attack the config comment says the auth
limiter models ("*failed* logins count — the limiter runs before
authentication, every attempt consumes").
`RateLimitCest::browserLoginFormIsRateLimitedBeforeTheFirewall()` pins the
ordering.

### A15. Sliding window, Redis storage

**(a)** A fixed window resets its counter at the boundary, so a limit of 5
per minute allows **10 in a few seconds** straddling the reset — 5 at
11:59:59 and 5 more at 12:00:00. That burst is exactly what an attacker
scripting the boundary would use. `sliding_window` weighs the previous
window proportionally into the current count (per the comment at the top of
`rate_limiter.yaml`), so the observed rate stays smooth and the 2x boundary
burst disappears.

**(b)** This stack runs multiple php-fpm workers; an in-memory counter is
per-process, so a limit of 5 becomes "5 × number of workers", and every
worker recycle or container restart resets everyone to zero — the limiter
would be decorative. The Redis `cache.rate_limiter` pool
(`config/packages/cache.yaml`) gives one shared, restart-surviving counter
across all workers. Note the pool comment's distinction: this is *counter
storage*, not a cache — losing it resets windows, nothing "rebuilds" it,
the app never invalidates it, entries just expire — which is why it is a
separate plain (non-tag-aware) pool from `tasks.cache`. `when@test` raises
both limits to 10000 because the functional suite fires hundreds of writes
at shared Redis state — real limits would make unrelated tests flake on
leftover counters. The 429 path itself is still covered, deterministically:
`tests/Unit/RateLimitListenerTest.php` uses in-memory storage with limit 2,
and `scripts/rate-limit-demo.sh` demonstrates the burst against the dev
stack.

## Caching

### A16. The invalidation contract

**Four write paths → `TeamTasksCacheInvalidator::invalidate($teamId)`:**
task create (`TaskController::create()`), task update
(`TaskController::update()`), task delete (`TaskController::delete()`), and
team delete (`TeamController::delete()`). **Two cached reads under the
`team_tasks.{teamId}` tag:** the task lists (`CachingTaskListProvider`, key
`task_list.{teamId}.{sha1(query shape)}`, TTL 60s) and the dashboard summary
(`TeamSummaryProvider`, key `team_summary.{teamId}`, TTL 300s). Sharing one
tag is itself a design choice: there is no separate summary-invalidation
path to forget.

**(a) Tag, not keys:** each *validated query shape* gets its own key —
every page/limit/status/assignee/sort/direction combination is a distinct
`sha1`. A write cannot enumerate which variants exist in Redis (any client
may have materialized any combination), so per-key deletion is impossible
even in principle. `invalidateTags(['team_tasks.'.$teamId])` wipes every
variant plus the summary in one call — the point of the
`cache.adapter.redis_tag_aware` pool.

**(b) After the flush:** invalidate-then-flush opens a race the
`TeamTasksCacheInvalidator` docblock names — a concurrent read arriving in
the gap finds the cache empty, rebuilds from the **pre-write** database
state, and re-caches it; the write then completes with stale data freshly
installed for a full TTL. Flushing first means any rebuild racing the
invalidation can at worst cache data that was true moments ago.

**(c) Accepted staleness:** the TTLs are the *backstop*, not the strategy
(`cache.yaml`'s comment). Anything a covered write changes is fresh
immediately; what the design accepts is bounded staleness — at most 60s
(lists) / 300s (summary) — for anything *outside* the four triggers, plus
the narrow post-flush race in (b). Comment writes need no invalidation
because comments are deliberately excluded from every cached payload:
`CachingTaskListProvider` caches lists without comments, and
`DashboardController::team()` fetches tasks and comments fresh, caching
only the summary counts. Shrinking the cached surface bought the freedom to
skip an entire invalidation path — the cheapest invalidation is the one you
do not need.

**The bug it protects against:** read-your-writes violations — you `POST` a
task, `GET` the list, and your task is missing (or the dashboard says
"2 tasks" after you added the third) for up to a minute. Precisely the
behavior `DashboardCest::dashboardShowsCachedSummaryCountsThatWritesKeepFresh()`
proves cannot happen: it asserts the summary key is a cache **hit** after a
render and a **miss** immediately after a task write. "Every write path must
invalidate, and the one you forget is the bug" — which is why the
`invalidateTags` call has exactly one home to audit.

## The SOLID refactor

### A17. Principles, symptoms, and what got easier

Per `docs/solid-refactor.md` (before-code quoted there verbatim):

- **SRP** — symptom: `TaskController::list()` at ~80 lines owning five jobs
  (param validation, sort whitelist, cache read-through, Doctrine query,
  pagination/mapping), with a closure capturing nine variables and the
  smoking gun `positiveIntParam(): int|JsonResponse` — parsing and HTTP
  responding fused into one signature. Fixed by the extractions:
  `src/Task/TaskListQuery.php` (all six param rules, throwing
  `InvalidTaskListQuery($field, $message)`) and
  `src/Task/DoctrineTaskListProvider.php` (DQL, pagination, mapping); the
  controller is back to twelve lines of authorize/parse/delegate/respond.
- **OCP** — symptom: stage 2 added caching *by editing the query method*,
  wrapping its body in `$this->tasksCache->get(...)`; every future concern
  meant editing it again. Fixed by
  `src/Task/CachingTaskListProvider.php`, a **decorator** implementing the
  same `TaskListProviderInterface` — caching became something you add
  *around* the query code, not into it.
- **DIP** — symptom: the controller depended on concrete mechanics
  (`TagAwareCacheInterface`, `TaskCacheKeys`, the TTL parameter, Doctrine's
  `Paginator`); nothing stood between "HTTP request" and "Redis key is
  hashed". Fixed by `src/Task/TaskListProviderInterface.php` plus the
  `services.yaml` wiring (`decorates:` + `'@.inner'`): the controller
  type-hints the interface and knows nothing else. The payoff line: caching
  is now *a DI configuration fact* — delete two `services.yaml` lines and
  the app runs uncached, no class changes.

**Easier, tests:** the whole validation table (page ≥ 1, limit 1–100, the
sort whitelist, `assignee=none`, which field each 422 blames) became
`tests/Unit/TaskListQueryTest.php` — pure PHP against `new Request([...])`,
no kernel, no DB, no Redis; before, it was reachable only through full HTTP
tests (`TaskListCest` still exists, but as a contract guard, not the only
door). **Easier, features:** the seams are now obvious — a new sort field is
one entry in `TaskListQuery::SORT_FIELDS` plus one in
`DoctrineTaskListProvider::SORT_DQL`; metrics or a different backend is
another decorator or a second `TaskListProviderInterface` implementation in
`services.yaml`; none of it touches the controller. (And cache invalidation
got one home: `src/Cache/TeamTasksCacheInvalidator.php`, replacing
duplicate cache-pool knowledge in two controllers.)

The guardrail worth remembering: the whole Api suite stayed green through
the refactor, and `TaskListQuery::statusValue()/assigneeValue()` reproduce
the exact strings the old code hashed — same HTTP contract, same cache
keys. A refactor is only a refactor if behavior is provably unchanged.

## Jenkins

### A18. What each stage alone catches

- **`composer validate --strict` + install** — a broken or drifted
  *manifest*: malformed `composer.json`, a `composer.lock` out of sync with
  it, invalid constraints. The other stages all run against whatever
  happens to be installed; only this stage checks that the declared
  dependency state is coherent and reproducible — the "works on my machine
  because my vendor/ is stale" class of bug.
- **PHP-CS-Fixer (`--dry-run --diff`)** — style drift: formatting and
  code-style rule violations. PHPStan does not care about formatting and
  the tests certainly pass with ugly code; only this stage keeps diffs
  review-sized and the style argument settled by a tool.
- **PHPStan (level 8, no baseline)** — type-level defects *without running
  the code*: a nullable passed where non-null is required, a wrong return
  type, a call on a possibly-null value — including on paths **no test
  executes**. Style checks don't see semantics, and Codeception only judges
  code it actually runs; static analysis is the only stage that reasons
  about every path. No baseline means no grandfathered debt.
- **Codeception (Api + Unit)** — actual runtime behavior and the HTTP
  contract: status codes, error shapes, the 404-cross-team policy, voter
  outcomes, cache invalidation, escaped dashboard output. Perfectly typed,
  perfectly formatted code that returns 403 where the contract says 404 is
  only caught here.

**Why `--dry-run`:** CI's job is to *veto*, not to repair — the Jenkinsfile
comment says it: "CI never rewrites code, it only vetoes drift." A CI that
auto-fixes either commits to your branch (mutating what you reviewed) or
fixes a copy and passes, letting the drift live on. Red build, you run the
fixer locally, the tree you ship is the tree that was judged.

**Why an isolated compose project:** the Codeception stage boots
`task-tracker-ci` (own MySQL, own Redis, no published ports, via
`compose.ci.yaml`) and tears it down `win or lose`. Testing against the dev
stack would let CI trample your in-progress dev data and state, let *your*
manual poking change CI's verdict (shared Redis counters, cache entries,
DB rows), and serialize builds behind whatever the dev stack is doing. A
gate's verdict is only meaningful if the environment is fresh, exclusive,
and disposable — same reason the suite runs in the same `task-tracker-php`
image as dev: byte-identical runtime, isolated state.
