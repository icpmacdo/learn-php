# Part 2 study guide — reading the task tracker

A guided reading path through the code, in the order the ideas build on each
other. Part 1 taught you the skeleton — kernel, routing, thin controllers,
DTO + Validator, the one error shape, Codeception against a real MySQL. All
of that is here unchanged. What's new in part 2 is everything that happens
*around* a request: who you are (authentication), what you may do
(authorization), how fast you may do it (rate limiting), how often the
database actually gets asked (caching), and what stops bad code from landing
(CI). Read with the stack running (`docker compose up -d --wait`) so you can
poke every claim with curl.

Each section names real files. When a claim sounds strong ("outsiders get
404, not 403"), the habit to build: find the test that proves it.

---

## 1. Life of an authenticated request

Part 1's requests were anonymous: nginx → `public/index.php` → routing →
controller. Nothing between the router and your code cared who was calling.
Follow one part-2 request end to end and you'll have seen every new layer in
its actual execution order:

```
POST /api/teams/1/tasks
Authorization: Bearer tt2_9f2c...
Content-Type: application/json

{"title": "Write the study guide", "priority": "high"}
```

Everything before the controller happens on the `kernel.request` event, and
**listener priority is the plot**. In descending order:

### 1.1 Routing (priority 32)

Symfony's `RouterListener` matches the path against
[`src/Controller/TaskController.php`](../src/Controller/TaskController.php)'s
`#[Route('/api/teams/{id}/tasks', ..., requirements: ['id' => '\d+'], methods: ['POST'])]`.
Same route-attribute style as part 1, including the `\d+` requirement so
`/api/teams/abc/tasks` is a routing-level 404 before any code runs.

### 1.2 Rate limiter, first hook (priority 16) — not this time

[`src/EventListener/RateLimitListener.php`](../src/EventListener/RateLimitListener.php)
has **two** hooks on `kernel.request` at different priorities. The first
(`onAuthEndpoint`, priority 16) runs *before* the firewall but only meters
the three credential-accepting paths in `AUTH_PATHS` — this request isn't
one, so it passes through untouched. Hold the thought; §6 explains why the
two hooks straddle the firewall.

### 1.3 The firewall (priority 8) — authentication

[`config/packages/security.yaml`](../config/packages/security.yaml) declares
two firewalls: `api` (pattern `^/api`, stateless, Bearer tokens) and `web`
(sessions + form login). Our path matches `api`, so the `access_token`
authenticator extracts `tt2_9f2c...` from the `Authorization` header and
hands it to
[`src/Security/ApiTokenHandler.php`](../src/Security/ApiTokenHandler.php):

1. Regex check: plain tokens look like `tt2_` + 64 hex chars.
2. `hash('sha256', $accessToken)` → lookup against the unique `token_hash`
   index. The plain token is **never stored**; there is nothing in the DB
   worth stealing. (Compare
   [`src/Controller/TokenController.php`](../src/Controller/TokenController.php)
   `issue()` — the plain token exists in exactly one response, ever.)
3. Any miss — wrong format, unknown, revoked — throws the same
   `BadCredentialsException`. No oracle.
4. On a hit: `touchLastUsed()`, flush, and a `UserBadge` whose closure hands
   back the already-loaded user so the user provider doesn't run a second
   query.

Then the `access_control` rules apply — **first match wins**. Our path isn't
`/api/register` or `/api/tokens` (the two `PUBLIC_ACCESS` exceptions), so
`^/api` demands `ROLE_USER`. We have a user; onward.

The failure exits, all before the controller exists:

| Situation | Handled by | Result |
|---|---|---|
| No `Authorization` header at all | [`src/Security/ApiAuthenticationEntryPoint.php`](../src/Security/ApiAuthenticationEntryPoint.php) | 401 "Authentication required." (the API's version of the web firewall's 302-to-`/login`) |
| Token present but invalid/revoked | [`src/Security/ApiAuthenticationFailureHandler.php`](../src/Security/ApiAuthenticationFailureHandler.php) | 401 "Invalid API token." — deliberately generic |

Both render part 1's error shape. Note what part 1 never had: a whole
subsystem that can end the request before routing's *output* is even used.

### 1.4 Rate limiter, second hook (priority 4) — now it counts

Back in `RateLimitListener`, `onApiWrite` fires *after* the firewall because
its key is `'user:'.$user->getId()` — which only exists post-authentication.
POST under `/api`, not an auth path, authenticated user present: one token is
consumed from the `api_write` sliding window (30/min,
[`config/packages/rate_limiter.yaml`](../config/packages/rate_limiter.yaml)).
Over budget → `TooManyRequestsHttpException` → 429 with `Retry-After`, still
in the one error shape.

### 1.5 The controller — thin, same as part 1, plus two new lines

`TaskController::create()` reads exactly like a part-1 action with two
insertions:

```php
$this->denyAccessUnlessGranted(TeamVoter::CREATE_TASK, $team);  // authorization (§3)
...
$this->cacheInvalidator->invalidate($id);                        // cache coherence (§6)
```

The full sequence: `teamOr404($id)` (find the team **and** check
`TeamVoter::VIEW` — a team you can't see "does not exist", §3.3), then the
`CREATE_TASK` voter check, then the part-1 input pipeline you already know:
[`src/Http/JsonBody.php`](../src/Http/JsonBody.php) decode (malformed body /
wrong field *type* → 400) →
[`src/Dto/CreateTaskRequest.php`](../src/Dto/CreateTaskRequest.php) →
Validator (bad *values* → per-field 422 via
[`src/Http/ApiProblem.php`](../src/Http/ApiProblem.php)) → entity → save.
Then the new final step: one call to
[`src/Cache/TeamTasksCacheInvalidator.php`](../src/Cache/TeamTasksCacheInvalidator.php)
wipes every cached task-list variant and the dashboard summary for this team
(§6.3), **after** the flush — invalidating first would let a concurrent read
re-cache the pre-write state. Response: 201 with the mapped task
([`src/Http/ApiResponseMapper.php`](../src/Http/ApiResponseMapper.php),
part 1's mapper pattern grown up).

If anything throws along the way,
[`src/EventListener/JsonExceptionListener.php`](../src/EventListener/JsonExceptionListener.php)
is still the single place errors become JSON — same design as part 1, now
also mapping voter denials (`AccessDeniedException` → 403) and the 429. Read
its class comment for the priority arithmetic (after the logger, before the
HTML renderer, and why 401s never reach it).

**The part-1 contrast, compressed:** part 1's pipeline was
routing → controller → DTO/Validator → entity → mapper. Part 2 wraps that
same pipeline in identity (1.3), permission (1.5), and throttling (1.2/1.4),
and appends cache coherence (1.5) — every wrapper a listener or firewall
config, none of it inline in controllers.

---

## 2. Authentication vs. authorization, and where each lives

Two different questions, answered in two different places, failing with two
different status codes:

| | Question | Lives in | Failure |
|---|---|---|---|
| Authentication | Who are you? | Firewalls in [`config/packages/security.yaml`](../config/packages/security.yaml) + [`src/Security/ApiTokenHandler.php`](../src/Security/ApiTokenHandler.php) | 401 (API) / 302 to `/login` (web) |
| Authorization | May *you* do *this*? | `access_control` (coarse) + voters in [`src/Security/Voter/`](../src/Security/Voter) (fine) | 403 — or 404, by policy (§3.3) |

### 2.1 Two firewalls = two auth worlds

Read the `firewalls` block in `security.yaml`. The `api` firewall is
`stateless: true` — no session is ever created; every request must present
the token again. The `web` firewall is the classic browser flow: `form_login`
with CSRF, a session cookie, a logout path with **no controller** (the
firewall's logout listener intercepts it —
[`src/Controller/SecurityController.php`](../src/Controller/SecurityController.php)
only renders the login form).

The worlds don't mix, and that's proven, not assumed:
`anApiTokenAloneDoesNotOpenTheDashboard` in
[`tests/Api/DashboardCest.php`](../tests/Api/DashboardCest.php) shows a
perfectly valid Bearer token being treated as anonymous on `/dashboard` —
the web firewall only speaks sessions, so the browser gets the 302.

### 2.2 Where credentials come from

- Passwords: hashed with `'auto'` (argon2id here) — see the comment at the
  top of `security.yaml`, and the `when@test` block that drops hasher cost so
  the suite stays fast *without changing behavior*.
- API tokens: the credential exchange in
  [`src/Controller/TokenController.php`](../src/Controller/TokenController.php).
  Three things worth reading in `issue()`: the identical 401 for
  unknown-email and wrong-password (no user enumeration), the CSPRNG token
  generation, and SHA-256-only persistence. Revocation (`revoke()`) uses a
  *scoped* lookup — someone else's token id 404s exactly like a nonexistent
  one.

### 2.3 Coarse vs. fine authorization

`access_control` answers path-shaped questions ("`^/api` needs `ROLE_USER`").
It cannot answer "may Ian delete task 42?" — that depends on *which* task,
*whose* team, *what role*. That's the voters' job — next section. The rule of
thumb this codebase follows: `access_control` for "logged in at all",
voters for everything touching a domain object. There are no other
authorization mechanisms — no `ROLE_ADMIN` global roles, no inline
membership ifs in controllers.

---

## 3. Voters: the permission matrix as code

### 3.1 The matrix

From the README (§Permissions), the behavior the whole suite pins:

| Action | Non-member | Member | Team admin |
|---|---|---|---|
| View team / members / tasks / comments | 404 | yes | yes |
| Rename / delete team | 404 | 403 | yes |
| Manage members, change roles | 404 | 403 (may remove **self** = leave) | yes (never the **last admin** → 422) |
| Create / edit tasks, comment | 404 | yes | yes |
| Delete task | 404 | creator only, else 403 | yes |
| Edit comment | 404 | author only, else 403 | author only |
| Delete comment | 404 | author only, else 403 | yes |

Now read the three files that *are* this table:
[`src/Security/Voter/TeamVoter.php`](../src/Security/Voter/TeamVoter.php),
[`src/Security/Voter/TaskVoter.php`](../src/Security/Voter/TaskVoter.php),
[`src/Security/Voter/CommentVoter.php`](../src/Security/Voter/CommentVoter.php).
Each is ~60 lines: a `supports()` filter (which attribute strings, which
subject class) and a `voteOnAttribute()` `match`. The alternative — the thing
voters exist to prevent — is this, repeated in every one of the nine
controllers:

```php
// The scattered-ifs world (nowhere in this codebase):
$membership = $this->memberships->findOneByUserAndTeam($user, $team);
if ($membership === null || $membership->getRole() !== TeamRole::Admin) { ... }
```

Scattered ifs drift: one controller checks role, another forgets the
membership check, a third compares the wrong user. Here, controllers only
ever say `denyAccessUnlessGranted(TaskVoter::DELETE, $task)` and the answer
comes from one place. Grep `src/Controller/` for `isGranted` — every
permission decision routes through a voter constant; there are no inline
role checks anywhere.

### 3.2 Walkthrough: `TaskVoter::DELETE`

The subtlest rule: delete is *creator or team admin*. In
`TaskVoter::voteOnAttribute()`:

```php
self::DELETE => $this->memberships->isAdmin($user, $team)
    || ($this->memberships->isMember($user, $team)
        && $task->getCreatedBy()->getUserIdentifier() === $user->getUserIdentifier()),
```

Three things to notice:

1. **Membership comes from a shared resolver.**
   [`src/Security/TeamMembershipResolver.php`](../src/Security/TeamMembershipResolver.php)
   is the one place that answers "what is this user's role in this team?" —
   all three voters inject it, and it memoizes per request because a single
   API call triggers multiple voter checks (the VIEW gate, then the action
   check) which must not mean multiple identical SELECTs. Read `forget()`
   too: membership *changes* mid-request must drop the memo.
2. **Identity is compared by email, not object identity.** The comment in
   the file explains why: the unit tests build entities in memory where two
   distinct users both have a null id.
3. **The creator check still requires membership.** A creator who left the
   team gets nothing — pinned by
   `testACreatorWhoLeftTheTeamCannotTouchTheirOldTask` in
   [`tests/Unit/TaskVoterTest.php`](../tests/Unit/TaskVoterTest.php).

Because voters are plain classes taking a token and a subject, the whole
matrix is unit-testable without HTTP, DB, or container:
[`tests/Unit/TeamVoterTest.php`](../tests/Unit/TeamVoterTest.php),
[`tests/Unit/TaskVoterTest.php`](../tests/Unit/TaskVoterTest.php),
[`tests/Unit/CommentVoterTest.php`](../tests/Unit/CommentVoterTest.php), on
the shared [`tests/Unit/VoterTestCase.php`](../tests/Unit/VoterTestCase.php).
The HTTP-level matrix (right status codes included) lives in the Api suite
([`tests/Api/TeamCest.php`](../tests/Api/TeamCest.php),
[`tests/Api/TaskCest.php`](../tests/Api/TaskCest.php),
[`tests/Api/CommentCest.php`](../tests/Api/CommentCest.php),
[`tests/Api/MemberCest.php`](../tests/Api/MemberCest.php)).

### 3.3 The 404-vs-403 policy

Look at the matrix's first column: outsiders get **404**, never 403. A 403
on `/api/teams/7` would confirm team 7 exists — an id-enumeration oracle.
So controllers layer the checks:

- `teamOr404()` / `taskOr404()` (in
  [`src/Controller/TaskController.php`](../src/Controller/TaskController.php)
  and friends) convert a `VIEW` denial into `NotFoundHttpException` — for an
  outsider, the resource does not exist.
- Only *after* you've passed the VIEW gate can an action check produce a 403
  — which then leaks nothing, because you're a member and already knew the
  team exists. That's the note in
  [`src/EventListener/JsonExceptionListener.php`](../src/EventListener/JsonExceptionListener.php)
  where `AccessDeniedException` becomes 403.

One deliberate edge: "leave team" is allowed *before* the
`MANAGE_MEMBERS` check in
[`src/Controller/MemberController.php`](../src/Controller/MemberController.php)
— leaving is not managing (see the TeamVoter class comment). And the
last-admin rule (a team must never end up admin-less) is a **422 business
rule** in the controller, not a voter concern: voters answer "may this user
do this", not "would this action leave the data invalid".

---

## 4. XSS in practice

### 4.1 The threat is stored, not reflected

Task titles, descriptions, comment bodies, team names — all user-authored,
all stored via the API, all rendered into HTML by the dashboard. Anyone on
the team can write `<script>alert('xss')</script>` as a task title; the
question is what happens when a *different* team member's browser renders it.

### 4.2 The defense: output encoding at render time

Read [`templates/dashboard/team.html.twig`](../templates/dashboard/team.html.twig).
Every interpolation is plain `{{ task.title }}` — and Twig autoescapes by
default (HTML context), so the stored payload renders as inert
`&lt;script&gt;...` text. The project-wide invariant is stronger than one
template: **no `|raw` filter exists anywhere** (grep `templates/`). The
defense isn't sanitizing input — the API stores and returns the string
byte-for-byte — it's encoding at the *output boundary*, per context.

### 4.3 The proof

`storedXssPayloadsRenderInert` in
[`tests/Api/DashboardCest.php`](../tests/Api/DashboardCest.php) is the
concrete version of the argument. Read it top to bottom — it's a story in
four acts: store hostile strings *through the API*; assert the JSON API
returns them **raw** (correct! safe in the `application/json` context); log
in through the real form and load the dashboard; assert the payloads are
present **as escaped text** and absent **as live markup**.

The middle act is the learning objective: *"we're just a JSON API" is not an
XSS defense.* JSON is safe in its own context, and precisely that makes the
raw strings someone else's problem the moment any consumer interpolates them
into HTML. This codebase happens to contain its own consumer (the
dashboard), which makes the hand-off visible in one test.

### 4.4 Belt and suspenders

[`src/EventListener/SecurityHeadersListener.php`](../src/EventListener/SecurityHeadersListener.php)
adds the second layer on every non-`/api` response: a CSP of
`default-src 'none'` (even markup that somehow survived escaping could not
load or run a script), `nosniff`, `DENY` framing, no referrer. Read the
class comment for why `/api` responses deliberately *don't* get these
browser-document directives —
`htmlResponsesCarrySecurityHeadersApiResponsesDoNot` in the same DashboardCest
pins both halves.

---

## 5. The dashboard: the one server-rendered page

Quick detour while you're in the templates.
[`src/Controller/DashboardController.php`](../src/Controller/DashboardController.php)
is session-world only (§2.1), applies the same `teamOr404()` policy as the
API (§3.3 — `nonMembersGetA404ForForeignTeamDashboards` proves it), and reads
its summary counts through
[`src/Cache/TeamSummaryProvider.php`](../src/Cache/TeamSummaryProvider.php)
— your first sight of the Redis read-through pattern before §6 does it
properly. Templates:
[`templates/base.html.twig`](../templates/base.html.twig) (the one `<style>`
block the CSP's `style-src 'unsafe-inline'` exists for),
[`templates/dashboard/index.html.twig`](../templates/dashboard/index.html.twig),
[`templates/dashboard/team.html.twig`](../templates/dashboard/team.html.twig),
[`templates/security/login.html.twig`](../templates/security/login.html.twig).

---

## 6. Redis: two patterns, one tool

One Redis container, two jobs that have almost nothing in common. The config
keeps them physically separate so the distinction stays visible — read
[`config/packages/cache.yaml`](../config/packages/cache.yaml) and
[`config/packages/rate_limiter.yaml`](../config/packages/rate_limiter.yaml)
side by side; both are written as commentary on exactly this point.

| | `tasks.cache` pool | `cache.rate_limiter` pool |
|---|---|---|
| Adapter | `cache.adapter.redis_tag_aware` | plain `cache.adapter.redis` |
| Holds | **derived data** — rebuildable from MySQL at any time | **counters** — losing them resets everyone's windows; not rebuildable, just tolerable to lose |
| Freshness | explicit invalidation by tag, TTL as backstop | never invalidated; entries expire |
| Consumer | [`CachingTaskListProvider`](../src/Task/CachingTaskListProvider.php), [`TeamSummaryProvider`](../src/Cache/TeamSummaryProvider.php) | `symfony/rate-limiter` via [`RateLimitListener`](../src/EventListener/RateLimitListener.php) |

### 6.1 Rate limiting

Two named limiters in `rate_limiter.yaml`, both **sliding window** — the
file's header comment explains why fixed windows are wrong (2× the limit in
a boundary-straddling burst). The interesting engineering is in
[`src/EventListener/RateLimitListener.php`](../src/EventListener/RateLimitListener.php):
one class, two `kernel.request` hooks at priorities **16 and 4**,
deliberately straddling the firewall at 8:

- `auth` (before the firewall, keyed by IP): must run first because on
  `POST /login` the form authenticator handles the request itself — a
  listener behind the firewall would never see the attempt. Running first is
  also what makes **failed** logins count; credential stuffing is the modeled
  attack.
- `api_write` (after the firewall, keyed by user id): the key doesn't exist
  until authentication has run. Per-user, so one client hammering writes
  can't exhaust anyone else's budget — and read the comment above the
  `$user instanceof User` check for the precise reasoning about why an
  unauthenticated request can never reach that hook unmetered.

Note also the double-charging guard (auth endpoints already paid the
stricter auth toll), and that limits are *parameters* with a `when@test`
override to 10 000/min — functional suites must never flake on shared
limiter state. The 429 behavior itself is covered from two angles:
[`tests/Unit/RateLimitListenerTest.php`](../tests/Unit/RateLimitListenerTest.php)
(in-memory storage, limit 2 — ten scenarios including per-IP vs per-user
keying) and [`tests/Api/RateLimitCest.php`](../tests/Api/RateLimitCest.php)
(the real 429 contract with tightened limits). And it's demonstrable against
the live stack: run
[`scripts/rate-limit-demo.sh`](../scripts/rate-limit-demo.sh) — `401 ×5`,
then `429` + `Retry-After`, then recovery.

### 6.2 Caching hot reads

Two reads go through `tasks.cache`: the task list (per team, **per query
shape**, TTL 60 s) and the dashboard summary (TTL 300 s). The mechanics live
in three small files:

- [`src/Cache/TaskCacheKeys.php`](../src/Cache/TaskCacheKeys.php) — the one
  place key/tag strings are built. Read the key scheme:
  `task_list.{teamId}.{sha1 of page|limit|status|assignee|sort|direction}`.
  Every page/filter/sort combination is its own entry — which is precisely
  what makes per-key invalidation impossible and sets up the tag design.
- [`src/Task/CachingTaskListProvider.php`](../src/Task/CachingTaskListProvider.php)
  — the read-through (`$this->tasksCache->get($key, fn ...)`): on a miss the
  closure runs (MySQL), sets TTL + tag, stores; on a hit MySQL is never
  touched. `cacheHitsServeFromRedisWithoutQueryingMysql` in
  [`tests/Api/CacheCest.php`](../tests/Api/CacheCest.php) proves the "never
  touched" part by writing to MySQL *behind the cache's back* and observing
  the stale read — the same test that shows you what TTL-only staleness
  looks like.
- [`src/Cache/TeamSummaryProvider.php`](../src/Cache/TeamSummaryProvider.php)
  — same pattern for the dashboard counts.

### 6.3 Explicit invalidation — the actual hard part

Both cached reads carry **one tag**: `team_tasks.{teamId}`. Every write path
that could change them — task create/update/delete, team delete — makes one
call to
[`src/Cache/TeamTasksCacheInvalidator.php`](../src/Cache/TeamTasksCacheInvalidator.php),
which wipes every list variant *and* the summary at once. Things to internalize:

- **Why tags:** you cannot enumerate the query-shape keys to delete them.
  One `invalidateTags()` call, all variants gone.
- **Why one class:** "every write path must invalidate, and the one you
  forget is the bug." The invalidator gives that rule a single audit point —
  grep for `cacheInvalidator->invalidate` and you've audited it.
- **Why after the flush:** the class comment explains the race.
- **Why comments aren't cached:** comment bodies are deliberately excluded
  from every cached payload (`GET /api/tasks/{id}` with its comments is
  always fresh), so comment writes need *no* invalidation at all —
  `commentWritesDoNotTouchTheCache` in
  [`tests/Api/CacheCest.php`](../tests/Api/CacheCest.php). Shaping the
  payload shrank the invalidation surface: a design move, not an
  implementation one.
- **TTL is the backstop**, not the strategy. Freshness comes from the write
  paths; TTL bounds the damage of the write path nobody enumerated.

Watch it live: `docker compose exec redis redis-cli monitor` while curling
the task list twice (one `GET` round-trip on the second hit) and then
creating a task (the tag wipe).

### 6.4 Shared-Redis hygiene

Dev and test share one Redis (unlike MySQL's separate `app`/`app_test`
databases). `cache.yaml`'s `prefix_seed: 'task-tracker.%kernel.environment%'`
is what keeps them out of each other's keyspaces. Cheap line, classic
production incident when missing.

---

## 7. The SOLID refactor, in the live code

Read [`docs/solid-refactor.md`](solid-refactor.md) in full first — it
contains the verbatim "before" (an 80-line `TaskController::list()` owning
five jobs, a closure capturing nine variables, a helper returning
`int|JsonResponse`) and names the principles violated with symptoms you can
point at. Then verify the "after" against the live files:

1. [`src/Controller/TaskController.php`](../src/Controller/TaskController.php)
   `list()` — twelve lines: authorize, parse, delegate, respond. The one HTTP
   thing it still does deliberately: converting `InvalidTaskListQuery` into a
   422 (the query object knows nothing about responses).
2. [`src/Task/TaskListQuery.php`](../src/Task/TaskListQuery.php) — the six
   parameter rules as an immutable value object; `fromRequest()` is the only
   way in, so an instance *existing* means the contract passed. Note
   `statusValue()`/`assigneeValue()`: they reproduce the exact raw-param
   strings the old code hashed, so the refactor didn't even cold-start the
   cache.
3. [`src/Task/TaskListProviderInterface.php`](../src/Task/TaskListProviderInterface.php)
   — the seam the controller depends on.
4. [`src/Task/DoctrineTaskListProvider.php`](../src/Task/DoctrineTaskListProvider.php)
   — the DQL build (`SORT_DQL` maps the whitelist to expressions, including
   the priority-rank CASE), pagination, mapping.
5. [`src/Task/CachingTaskListProvider.php`](../src/Task/CachingTaskListProvider.php)
   — caching as a **decorator** implementing the same interface.
6. [`config/services.yaml`](../config/services.yaml) — the wiring: the
   interface aliases to the Doctrine provider, and `CachingTaskListProvider`
   `decorates` it with `$inner: '@.inner'`. This is the payoff line of the
   whole section: **caching is now a DI-configuration fact, not a property of
   the query code.** Delete two YAML lines and the app runs uncached, no
   class touched.

The testability proof:
[`tests/Unit/TaskListQueryTest.php`](../tests/Unit/TaskListQueryTest.php)
drives the entire validation table as pure PHP in milliseconds — before the
refactor those rules were only reachable through full HTTP tests.
[`tests/Api/TaskListCest.php`](../tests/Api/TaskListCest.php) still exists,
but now it guards the HTTP *contract* rather than being the only door to the
logic. And the refactor's own safety net was that Api suite: byte-identical
external behavior, green throughout.

One vocabulary note for part 3: this is dependency inversion and
decoration doing real work, not pattern collecting — each extraction is
justified by a concrete pain (untestable rules, duplicated cache knowledge,
an `int|JsonResponse` signature).

---

## 8. CI as a gate

Start the Jenkins stack (`docker compose -f jenkins/compose.yaml up -d
--wait`, UI on :8082) and read the
[`Jenkinsfile`](../Jenkinsfile) top to bottom — the header comment explains
the architecture: every stage runs in the app's own `task-tracker-php` image
as a sibling container, so CI's runtime is byte-identical to dev's and
nothing touches the host. The supporting cast is all code, zero setup
clicks: [`jenkins/Dockerfile`](../jenkins/Dockerfile),
[`jenkins/plugins.txt`](../jenkins/plugins.txt),
[`jenkins/casc.yaml`](../jenkins/casc.yaml) (JCasC config + the seed job
that re-`load`s the Jenkinsfile from the bind-mounted repo every build, so
the gate always judges your current working tree),
[`jenkins/compose.yaml`](../jenkins/compose.yaml).

The stages, and what class of defect each one catches:

| Stage | Tool | Catches |
|---|---|---|
| Snapshot workspace | rsync | — (isolation: CI judges a copy, never mutates your tree) |
| composer validate + install | `composer validate --strict` | broken/ambiguous manifest, lock drift — the "works on my machine because my vendor/ is special" class |
| PHP-CS-Fixer | `fix --dry-run --diff` | style drift. `--dry-run` is the philosophical bit: **the gate vetoes, it never rewrites** — code changes only happen where a human runs the fixer |
| PHPStan | level 8, `src/` + `tests/`, no baseline | type-level wrongness a dynamic language happily runs: wrong returns, nullable derefs, impossible comparisons. The Doctrine extension boots the real kernel ([`tests/object-manager.php`](../tests/object-manager.php)) so ORM metadata is known, not guessed |
| Codeception | Api + Unit vs. an isolated compose project ([`compose.ci.yaml`](../compose.ci.yaml): own MySQL + Redis, no published ports, `down -v` win or lose) | behavioral regressions — everything sections 1–7 pinned |

Note the ordering is cheapest-first: a busted `composer.json` fails in
seconds, not after a five-minute test run.

**Why red blocks merge.** The pipeline is the *definition of done* — while
it's red, nothing lands, not "merge now, fix style later", because later is
where drift compounds and broken windows normalize. Each stage encodes a
promise every future change silently relies on: the styles won't diverge,
level 8 stays clean *without a baseline* (the first suppressed error makes
the second invisible), the whole matrix still holds against real MySQL and
Redis. On a team the discipline is mechanical (branch protection + required
status checks); solo, it's a rule you keep. It ran for real here: a
deliberate `return 'not an int';` from an `int`-returning method turned a
build red at the PHPStan stage naming the exact line — a defect no human
review ever saw — and deleting it turned the next build green (README,
§"CI: Jenkins as the quality gate").

---

## 9. Questions to sit with

The quiz will echo these. Don't look answers up first — reason from what you
read, then verify in the code.

1. A request hits `POST /login` with wrong credentials for the sixth time in
   a minute. Walk the `kernel.request` priorities: what runs, in what order,
   and why would moving `onAuthEndpoint` to priority 6 silently break the
   protection?
2. Why does `GET /api/teams/7` return 404 — not 403 — for a non-member, while
   `PATCH /api/teams/7` returns 403 for a member? What attack does each
   status choice defend against, and where in the code is each conversion
   made?
3. The database stores only `sha256(token)`. Enumerate what an attacker with
   a full dump of the `api_token` table can and cannot do, and contrast with
   what a dump of a *password* column protected the same way would mean.
   Why is the token hashed with plain SHA-256 but passwords with argon2id?
4. `TaskVoter::DELETE` checks `isMember(...) && createdBy === user` rather
   than just the creator comparison. Construct the scenario the extra
   membership check prevents, and name the unit test that pins it.
5. The JSON API returns `<script>alert('xss')</script>` completely raw and
   the test *asserts* that it does. Defend that as correct behavior. What
   exactly would break, and for whom, if the API HTML-escaped on the way out
   instead?
6. Why do the cached task lists use a tag-aware adapter while the rate
   limiter's pool is a plain one? What would per-key deletion of task-list
   entries require, and why is it impossible here?
7. Comment writes call no cache invalidation, and a test proves the cache
   isn't touched. What payload-design decision makes that correct, and what
   would silently break if someone added `comments` to the cached task-list
   payload without touching the invalidator?
8. In `TeamTasksCacheInvalidator`, why must `invalidate()` run *after* the
   flush? Describe the interleaving with a concurrent reader that breaks if
   you swap the order, and what bounds the damage when it happens anyway.
9. The stage-3 refactor left the controller converting
   `InvalidTaskListQuery` to a 422 instead of letting `TaskListQuery` return
   a `JsonResponse` itself. Why is that boundary the right place for the
   conversion — what did the codebase look like when the parsing layer *did*
   know about responses?
10. Caching is applied via `decorates:` in `services.yaml` rather than a
    `$useCache` flag inside the Doctrine provider. Which SOLID principles
    does the flag version violate, and what concrete future change (pick one
    from `docs/solid-refactor.md`'s list) does the decorator version make a
    no-code-change edit?
11. CI runs PHP-CS-Fixer with `--dry-run` and PHPStan with no baseline. What
    goes wrong, socially and technically, with a pipeline that auto-fixes
    style? With one that baselines existing static-analysis errors?
12. The Jenkins pipeline judges the rsync'd *working tree*, not a git clone.
    What does the clone version silently fail to gate in this solo setup, and
    why does the trade-off flip on a team?

When you can answer these from memory *and* point at the file that proves
each answer, you're done studying — move to the exercises.
