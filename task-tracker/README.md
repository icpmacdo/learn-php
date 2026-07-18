# Part 2 — Team task tracker

A multi-user task tracker API: users belong to teams (as `member` or `admin`),
teams have tasks, tasks have comments. Everything part 1 skipped: real
authentication (sessions **and** API tokens), authorization with Symfony
voters, pagination/filtering/sorting, Redis rate limiting **and** caching,
and a server-rendered Twig dashboard with concrete XSS mitigation — all
gated by a dockerized Jenkins pipeline (composer validate → PHP-CS-Fixer →
PHPStan → Codeception).

Part 1 (link shortener) keeps port 8080; this project owns **8081** (API)
and **8082** (Jenkins).

## Run it

Everything runs in Docker; nothing is installed on the host.

```bash
docker compose up -d --wait     # build, install deps, wait for MySQL, migrate dev+test DBs
```

Then the API is at `http://localhost:8081` and the browser login at
`http://localhost:8081/login`. From wiped volumes the first start takes a
minute (composer install + MySQL init).

All PHP commands run inside the `php` container:

```bash
docker compose exec php bin/console debug:router   # any console command
docker compose exec php composer install
docker compose exec php vendor/bin/codecept run    # the whole test suite
```

## Auth walkthrough (curl)

Two auth worlds, two firewalls:

| Firewall | Pattern | Mechanism |
|---|---|---|
| `api` | `^/api` | stateless Bearer tokens (`Authorization: Bearer tt2_...`) |
| `web` | everything else | form login at `/login`, session cookie, `/dashboard` |

**1. Register** (public):

```bash
curl -s -X POST http://localhost:8081/api/register \
  -H 'Content-Type: application/json' \
  -d '{"email":"ian@example.com","password":"password123","displayName":"Ian"}'
# {"id":1,"email":"ian@example.com","displayName":"Ian"}
```

**2. Exchange credentials for an API token** (public; the plain token is shown
exactly **once** — only its SHA-256 hash is stored):

```bash
curl -s -X POST http://localhost:8081/api/tokens \
  -H 'Content-Type: application/json' \
  -d '{"email":"ian@example.com","password":"password123","name":"laptop curl"}'
# {"id":1,"name":"laptop curl","token":"tt2_<64 hex chars>","createdAt":"..."}
TOKEN=tt2_...   # save it; the server can never show it again
```

Unknown email and wrong password return the identical
`401 {"errors":[{"field":null,"message":"Invalid credentials."}]}` — no user
enumeration.

**3. Use it** on every `/api` request:

```bash
curl -s http://localhost:8081/api/me -H "Authorization: Bearer $TOKEN"
```

**4. List / revoke** tokens (revocation kills the token on the very next request):

```bash
curl -s http://localhost:8081/api/tokens -H "Authorization: Bearer $TOKEN"   # never shows values
curl -s -X DELETE http://localhost:8081/api/tokens/2 -H "Authorization: Bearer $TOKEN"   # 204
```

**Browser:** `http://localhost:8081/login` → session → `/dashboard`. API
tokens do not work on web pages, sessions do not work on `/api`.

## Error shape and the 401/403/404 policy

Every API error uses part 1's single shape, rendered by one
`kernel.exception` listener:

```json
{ "errors": [ { "field": "title", "message": "This value should not be blank." } ] }
```

`422` validation (per-field), `400` malformed JSON / wrong field *type*,
`401`/`403`/`404`/`405`/`429`/`500` with `field: null` (the `429` also
carries a `Retry-After` header; every `POST`/`PATCH`/`DELETE` under `/api` is
rate-limited — see the Redis section).

The policy (pinned, deliberate):

- **401** — who are you? Missing/invalid/revoked token.
- **404** — the resource doesn't exist **or is in a team you don't belong
  to**. Outsiders never learn a team/task/comment exists (`TEAM_VIEW`-style
  voter denials are converted to 404 — no id-enumeration oracle).
- **403** — you can *see* the resource but can't do *this* to it (member
  renaming a team, non-author editing a comment). Only ever shown to members,
  so it leaks nothing.

## Permissions (enforced by voters, never inline ifs)

| Action | Non-member | Member | Team admin |
|---|---|---|---|
| View team / members / tasks / comments | 404 | yes | yes |
| Rename / delete team | 404 | 403 | yes |
| Manage members, change roles | 404 | 403 (may remove **self** = leave) | yes (never demote/remove the **last admin** → 422) |
| Create / edit tasks, comment | 404 | yes | yes |
| Delete task | 404 | creator only, else 403 | yes |
| Edit comment | 404 | author only, else 403 | author only (moderation = delete, not edit) |
| Delete comment | 404 | author only, else 403 | yes |

`TeamVoter`, `TaskVoter`, `CommentVoter` share one `TeamMembershipResolver`
(one membership query, memoized per request).

## API reference

Base URL `http://localhost:8081`. All bodies JSON. All `{id}` params are `\d+`
at the routing level.

### Auth & tokens

| Method & path | Auth | Success | Errors |
|---|---|---|---|
| `POST /api/register` `{email, password, displayName}` | public | 201 User | 400, 422, 429 |
| `POST /api/tokens` `{email, password, name}` | public | 201 `{id, name, token, createdAt}` | 400, 401, 422, 429 |
| `GET /api/me` | token | 200 User | 401 |
| `GET /api/tokens` | token | 200 `{tokens: [...]}` | 401 |
| `DELETE /api/tokens/{id}` | token | 204 | 401, 404 (not yours / unknown) |
| `GET /login`, `POST /login` (form), `/logout` | web | 302 dance | |

### Teams & members

| Method & path | Who | Success | Errors |
|---|---|---|---|
| `POST /api/teams` `{name}` | any user (becomes admin) | 201 Team | 422 |
| `GET /api/teams` | any user (own teams only) | 200 `{teams: [...]}` | |
| `GET /api/teams/{id}` | member | 200 Team | 404 |
| `PATCH /api/teams/{id}` `{name}` | admin | 200 Team | 403, 404, 422 |
| `DELETE /api/teams/{id}` | admin | 204 (cascades) | 403, 404 |
| `GET /api/teams/{id}/members` | member | 200 `{members: [...]}` | 404 |
| `POST /api/teams/{id}/members` `{email, role}` | admin | 201 Member | 403, 404, 422 |
| `PATCH /api/teams/{id}/members/{userId}` `{role}` | admin | 200 Member | 403, 404, 422 (last admin) |
| `DELETE /api/teams/{id}/members/{userId}` | admin, or self (leave) | 204 | 403, 404, 422 (last admin) |

### Tasks & comments

| Method & path | Who | Success | Errors |
|---|---|---|---|
| `POST /api/teams/{id}/tasks` | member | 201 Task | 404, 422 |
| `GET /api/teams/{id}/tasks` | member | 200 paginated (below) | 404, 422 |
| `GET /api/tasks/{id}` | member | 200 Task + `comments` (oldest first) | 404 |
| `PATCH /api/tasks/{id}` (any subset) | member | 200 Task | 404, 422 |
| `DELETE /api/tasks/{id}` | creator or admin | 204 | 403, 404 |
| `POST /api/tasks/{id}/comments` `{body}` | member | 201 Comment | 404, 422 |
| `PATCH /api/comments/{id}` `{body}` | author | 200 Comment | 403, 404, 422 |
| `DELETE /api/comments/{id}` | author or admin | 204 | 403, 404 |

Task body: `{title (required, ≤255), description? (≤10000), status? (todo |
in_progress | done), priority? (low | medium | high), assigneeId? (must be a
team member), dueDate? (Y-m-d)}`. On PATCH, an explicit `null` clears
`description` / `assigneeId` / `dueDate`.

### Pagination / filtering / sorting — `GET /api/teams/{id}/tasks`

| Param | Rule | Default |
|---|---|---|
| `page` | int ≥ 1 | 1 |
| `limit` | int 1–100 | 20 |
| `status` | `todo` \| `in_progress` \| `done` | all |
| `assignee` | user id, or the literal `none` (unassigned) | all |
| `sort` | `createdAt` \| `dueDate` \| `priority` (by rank: high > medium > low) \| `status` | `createdAt` |
| `direction` | `asc` \| `desc` | `desc` |

Anything off-contract is a **422** naming the parameter — never a silent
fallback. Response:

```json
{ "tasks": [ ... ], "page": 1, "limit": 20, "total": 57, "pages": 3 }
```

## Redis: one tool, two patterns

One Redis container plays two deliberately separate roles, configured as two
separate pools in `config/packages/cache.yaml`:

| Role | Pool | Adapter | Nature of the data |
|---|---|---|---|
| Rate limiting | `cache.rate_limiter` | plain Redis | counters — losing them resets everyone's windows; never invalidated, entries just expire |
| Response caching | `tasks.cache` | **tag-aware** Redis | derived data — rebuildable from MySQL at any time; explicitly invalidated by tag on writes |

Redis itself runs with no persistence and no published port: everything it
holds is disposable, and only containers may talk to it.

### Rate limiting

`symfony/rate-limiter`, **sliding window** policy (a fixed window lets 2× the
limit through in a burst straddling the boundary), enforced by one
`RateLimitListener` with two `kernel.request` hooks:

| Limiter | Applies to | Key | Dev limit | Test limit |
|---|---|---|---|---|
| `auth` | `POST /api/register`, `POST /api/tokens`, `POST /login` | client IP | **5/min** | 10 000/min |
| `api_write` | every other `POST`/`PATCH`/`DELETE` under `/api` | user id | **30/min** | 10 000/min |

The `auth` hook runs **before** the security firewall (failed logins must
count — credential stuffing is the modeled attack — and on `POST /login` the
form authenticator would otherwise swallow the request first); the
`api_write` hook runs **after** it, because its key is the authenticated
user. Limits live in parameters (`config/packages/rate_limiter.yaml`), and
`when@test` relaxes them so suites stay deterministic.

Over the limit → **429** in the standard error shape plus `Retry-After`:

```json
{ "errors": [ { "field": null, "message": "Too many requests. Retry after 12 seconds." } ] }
```

**See it live** (dev stack on 8081):

```bash
scripts/rate-limit-demo.sh
```

fires 7 rapid bad-credential `POST /api/tokens`: `401 ×5`, then `429` with
`Retry-After`, then waits it out and shows a `401` again — limit, push-back
and recovery in one run.

### Caching with explicit invalidation

Two hot reads go through the tag-aware pool:

| Cached read | Key | TTL |
|---|---|---|
| `GET /api/teams/{id}/tasks` (per query shape) | `task_list.{teamId}.{sha1(page\|limit\|status\|assignee\|sort\|direction)}` | 60 s |
| Dashboard summary counts | `team_summary.{teamId}` | 300 s |

Both carry the **one tag** `team_tasks.{teamId}`. Task create/update/delete
and team delete call `invalidateTags()` — one call wipes every page/filter/
sort variant *and* the summary at once, which is why tags and not per-key
deletes. Comments are deliberately excluded from every cached payload, so
comment writes need no invalidation at all: shaping the payload shrank the
invalidation surface. TTL is only the backstop for the write path you forgot
to enumerate (the suite demonstrates that failure mode by writing to MySQL
behind the cache's back). Watch it live with
`docker compose exec redis redis-cli monitor` while listing tasks.

## Dashboard (Twig) and XSS

`GET /dashboard` (your teams + cached counts) and `GET /dashboard/teams/{id}`
(counts, tasks, comments) are server-rendered and **session-only**: anonymous
browsers get a 302 to `/login`, and an API token means nothing here — the web
firewall only speaks sessions. Non-members get the same 404 policy as the API.

Team names, task titles/descriptions and comment bodies are user-authored
content rendered into HTML. The defenses, in layers:

1. **Twig autoescaping** (on by default, no `|raw` anywhere): a stored
   `<script>alert('xss')</script>` title renders as inert
   `&lt;script&gt;...` text. The Api suite stores that title and an
   `<img src=x onerror=alert(1)>` comment via the API and asserts the
   dashboard HTML contains only the escaped forms — the JSON API returns the
   same strings raw, safe *in that context*, which is exactly why "we're just
   an API" is not an XSS defense.
2. **Security headers** on every non-`/api` response
   (`SecurityHeadersListener`): a CSP of `default-src 'none'` (no script may
   load or execute at all), `X-Content-Type-Options: nosniff`,
   `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`.

## Tests

```bash
docker compose exec php vendor/bin/codecept run          # both suites
docker compose exec php vendor/bin/codecept run Api      # HTTP semantics vs app_test DB
docker compose exec php vendor/bin/codecept run Unit     # pure logic, no DB
```

The Api suite covers the full permission matrix (outsider → 404,
over-privileged member → 403, admin → 2xx for every operation), the token
lifecycle, form login, pagination/filter/sort math, every validation error,
the 429 contract (shape + `Retry-After` + recovery), cache freshness after
every write path (plus proof the cache is really serving reads), the
dashboard session rules and the XSS escape assertions. The Unit suite proves
the voters, the rate-limit listener (in-memory storage, limit 2), the cache
key builder and the `TaskListQuery::fromRequest()` validation table as pure
logic.

## Code quality: PHP-CS-Fixer + PHPStan

Both run inside the `php` container, both are CI stages, both pass with
zero exceptions (no baseline, no ignores):

```bash
docker compose exec php vendor/bin/php-cs-fixer fix              # rewrite to style
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff   # what CI runs
docker compose exec php vendor/bin/phpstan analyse               # level 8, src + tests
```

- **Style** (`.php-cs-fixer.dist.php`): `@Symfony` ruleset plus
  `declare_strict_types`, minus Yoda conditions. CI runs `--dry-run`: the
  gate vetoes drift, it never rewrites code.
- **Static analysis** (`phpstan.dist.neon`): level 8 over `src/` *and*
  `tests/`, with the Doctrine extension booting the real kernel
  (`tests/object-manager.php`) so ORM metadata — generated ids, DQL fields —
  is known, not guessed.

## SOLID refactor

The accreted task-list read path (`TaskController::list()` had grown query
parsing, the sort whitelist, cache keys, the read-through, the Doctrine
query and pagination in one method) was refactored in stage 3 into
`TaskListQuery` + `TaskListProviderInterface` + `DoctrineTaskListProvider` +
a `CachingTaskListProvider` decorator wired in `services.yaml`, with cache
invalidation centralized in `TeamTasksCacheInvalidator`. Full before/after
with the principles named: **[docs/solid-refactor.md](docs/solid-refactor.md)**.

## CI: Jenkins as the quality gate

A dockerized Jenkins with its own compose project — app dev and CI have
independent lifecycles. Everything is code: plugins (`jenkins/plugins.txt`),
config + admin user (`jenkins/casc.yaml`, JCasC), and the job itself (a Job
DSL seed in the same file). Zero setup clicks.

```bash
docker compose -f jenkins/compose.yaml up -d --wait   # from task-tracker/
open http://localhost:8082                            # login: admin / admin (local-only toy)
```

**Trigger a build:** click *task-tracker → Build Now*, or from the CLI:

```bash
CRUMB=$(curl -s -c /tmp/jc.txt -u admin:admin \
  'http://localhost:8082/crumbIssuer/api/xml?xpath=concat(//crumbRequestField,":",//crumb)')
curl -b /tmp/jc.txt -u admin:admin -H "$CRUMB" -X POST http://localhost:8082/job/task-tracker/build
```

**Read results:** the job page lists builds (green ball = passed); a build's
*Console Output* shows each stage — the stage that failed prints the exact
tool output (the PHPStan error, the CS diff, the failing test).

**What a build does** (`Jenkinsfile`, stages in order — any red fails the
build): rsync the *working tree* into an isolated CI workspace → `composer
validate --strict` + install → PHP-CS-Fixer `--dry-run` → PHPStan level 8 →
Codeception (Api + Unit) against a throwaway compose project
(`task-tracker-ci`: own MySQL, Redis and volumes, **no published ports**,
`down -v` win or lose). Every stage runs in the app's own `task-tracker-php`
image via the mounted docker socket, so CI's runtime is byte-identical to
dev's and nothing is installed on the host.

**How the pipeline gets the code:** the repo is bind-mounted read-only at
`/repo` in the Jenkins container; each build copies the current
`Jenkinsfile` from there and rsyncs the working tree. (The PRD sketched a
`git clone` from `file:///repo`; a clone only ships *committed* work, and
the whole point of this gate is to judge the working tree **before** it is
committed — the mount approach is the honest version of that. In a team
setting the same pipeline would run from real SCM per branch.)

**CI as a gate — why red blocks merge:** the pipeline is the definition of
done. While it is red, nothing lands on `main` — not "merge now, fix style
later", because *later* is where drift compounds and broken windows
normalize. Green means: manifest valid, zero style drift, zero level-8
static errors, and 107 tests passing against real MySQL and Redis. On a
team this discipline is enforced mechanically (branch protection + required
status checks); solo, it is a rule you keep. It was demonstrated live here:
a deliberate type error (`return 'not an int';` from an `int` method) turned
build #3 red at the PHPStan stage naming the exact line, and deleting it
turned build #5 green — the gate caught the defect no human review was ever
shown.

## Roadmap (stages)

1. **(done)** Scaffold, entities+migrations, auth (sessions + hashed API
   tokens), voters, task/comment CRUD, green Api+Unit suites.
2. **(done)** Redis rate limiting (auth + write endpoints) & tag-aware
   response caching with explicit invalidation; Twig team dashboard with the
   XSS proof and security headers; `scripts/rate-limit-demo.sh`.
3. **(done)** PHP-CS-Fixer, PHPStan (level 8), dockerized Jenkins pipeline
   as the quality gate, and the documented SOLID refactor of the task-list
   read path (`docs/solid-refactor.md`).
