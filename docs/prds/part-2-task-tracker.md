# PRD — Part 2: Team task tracker

Expands [the one-page spec](../specs/part-2-task-tracker.md). The spec is the source of truth for scope; this document nails down the contracts, data model, security design, and build order.

**Project directory:** `part-2-task-tracker/` (self-contained: own `compose.yaml`, own `README.md`, own Jenkins compose under `jenkins/`).

**Stack:** PHP 8.3, Symfony (current stable, minimal skeleton), Doctrine ORM + Migrations, MySQL 8, Redis 7, Symfony Security (form login, access tokens, voters), symfony/rate-limiter, Twig, Codeception (Api + Unit), PHP-CS-Fixer, PHPStan, Jenkins (dockerized, JCasC). API at `http://localhost:8081`, Jenkins at `http://localhost:8082`. Part 1 keeps 8080 — its containers, files, and ports are untouched.

**Out of scope (deliberate):** microservices, async messaging, deployment, DDD vocabulary, email verification, password reset, user profile editing, file attachments, websockets.

**Conventions carried from part 1:** DTO + Validator input handling; one JSON error shape rendered by a single `kernel.exception` listener; route param requirements regexes (`requirements: ['id' => '\d+']` everywhere an id appears); thin controllers; migrations only (never `schema:update`); entrypoint waits for MySQL and migrates the dev **and** test databases; all composer/console/codecept commands run inside the `php` container.

---

## 1. User stories

1. As a person, I can register and log in, so the system knows who I am (browser session or API token).
2. As a user, I can create a team (becoming its admin) and invite other registered users as members or admins.
3. As a team member, I can create, list (with pagination/filtering/sorting), edit, and comment on my team's tasks.
4. As a team admin, I can manage membership, rename or delete the team, and delete any task or comment (moderation).
5. As a non-member, I cannot see that another team, its tasks, or its comments even *exist*.
6. As an API client, I exchange credentials for a token once, then authenticate every request with it, and I can revoke it.
7. As a browser user, I see a server-rendered team dashboard, and content typed by other users cannot run script in my browser.
8. As the learner (Ian), I can hammer an endpoint and watch the rate limiter push back, and run one Jenkins job that gates the whole codebase.

## 2. Data model

Six tables. All timestamps `DATETIME`, stored UTC, set in entity constructors/mutators. All PKs `BIGINT UNSIGNED` auto-increment. Enum-like columns are `VARCHAR` + a PHP backed enum + a CHECK-by-validation (app-level) — pedagogical: native MySQL ENUMs make migrations painful; the enum lives in code.

**`user`** (`App\Entity\User`, implements `UserInterface`, `PasswordAuthenticatedUserInterface`)

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `email` | VARCHAR(180) | **UNIQUE** `uniq_user_email`, NOT NULL |
| `password_hash` | VARCHAR(255) | NOT NULL |
| `display_name` | VARCHAR(100) | NOT NULL |
| `created_at` | DATETIME | NOT NULL |

**`team`** (`App\Entity\Team`)

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `name` | VARCHAR(100) | NOT NULL |
| `created_at` | DATETIME | NOT NULL |

**`team_membership`** (`App\Entity\TeamMembership`) — the join with a payload; the heart of authorization.

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `user_id` | FK → user | NOT NULL, ON DELETE CASCADE |
| `team_id` | FK → team | NOT NULL, ON DELETE CASCADE |
| `role` | VARCHAR(10) | NOT NULL — `member` \| `admin` (`App\Enum\TeamRole`) |
| `created_at` | DATETIME | NOT NULL |

Indexes: **UNIQUE** `uniq_membership (user_id, team_id)` — one membership per user per team; `idx_membership_team (team_id)` for member listings.

**`task`** (`App\Entity\Task`)

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `team_id` | FK → team | NOT NULL, ON DELETE CASCADE |
| `title` | VARCHAR(255) | NOT NULL |
| `description` | TEXT | NULL |
| `status` | VARCHAR(20) | NOT NULL — `todo` \| `in_progress` \| `done` (`App\Enum\TaskStatus`), default `todo` |
| `priority` | VARCHAR(10) | NOT NULL — `low` \| `medium` \| `high` (`App\Enum\TaskPriority`), default `medium` |
| `assignee_id` | FK → user | NULL, **ON DELETE SET NULL** — a task outlives its assignee |
| `due_date` | DATE | NULL |
| `created_by_id` | FK → user | NOT NULL, ON DELETE CASCADE |
| `created_at` / `updated_at` | DATETIME | NOT NULL |

Indexes: `idx_task_team_status (team_id, status)` and `idx_task_team_assignee (team_id, assignee_id)` — the two filter paths; `idx_task_team_created (team_id, created_at)` — the default list sort. Every task query is team-scoped, so `team_id` leads each index.

**`comment`** (`App\Entity\Comment`)

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `task_id` | FK → task | NOT NULL, ON DELETE CASCADE |
| `author_id` | FK → user | NOT NULL, ON DELETE CASCADE |
| `body` | TEXT | NOT NULL (max 5000 chars, app-validated) |
| `created_at` | DATETIME | NOT NULL |

Index: `idx_comment_task (task_id)`.

**`api_token`** (`App\Entity\ApiToken`)

| Column | Type | Constraints |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK |
| `user_id` | FK → user | NOT NULL, ON DELETE CASCADE |
| `token_hash` | CHAR(64), `ascii_bin` | **UNIQUE** `uniq_api_token_hash`, NOT NULL — SHA-256 hex of the plain token |
| `name` | VARCHAR(100) | NOT NULL — client-supplied label ("laptop curl") |
| `created_at` | DATETIME | NOT NULL |
| `last_used_at` | DATETIME | NULL — touched on authentication |

**Ownership/cascade summary:** delete a team → memberships, tasks, and (transitively) comments go with it. Delete a task → comments go. Users are never deleted in this part (no endpoint), but FKs are still correct: CASCADE for authored/owned rows, SET NULL for assignee.

## 3. Authentication design

**Password hashing:** Symfony `password_hashers: 'auto'` (argon2id where sodium is available, bcrypt otherwise — never configure a raw algorithm by hand). Registration validates: `email` NotBlank + Email + app-level uniqueness (422 `"This email is already registered."` on the `email` field, surfaced from the unique index, not a racy pre-check — same lesson as part 1's code collision); `password` NotBlank + Length(min 8, max 64); `displayName` NotBlank + Length(max 100).

**Two firewalls, two auth worlds** (`security.yaml`):

| Firewall | Pattern | Mechanism | Stateful? |
|---|---|---|---|
| `api` | `^/api` | `access_token` authenticator with a custom `ApiTokenHandler` | stateless |
| `web` | everything else | `form_login` (Twig form at `/login`), `logout` at `/logout` | session (native file sessions; Redis session storage deliberately not used — Redis already stars in two other roles this part) |

Public exceptions inside `api`: `POST /api/register` and `POST /api/tokens` (`PUBLIC_ACCESS` in `access_control`). Everything else under `/api` requires a valid Bearer token; `/dashboard*` requires a session (anonymous browser users get a 302 to `/login` — the browser-appropriate "401").

**API tokens — issue, store, send, revoke:**

- **Issue:** `POST /api/tokens` with JSON `{ "email", "password", "name" }` — credential exchange, no prior auth. On success: 201 with the **plain token, shown exactly once**: `tt2_` + 64 hex chars (`bin2hex(random_bytes(32))`, CSPRNG).
- **Store:** only `sha256(plain)` is persisted (`token_hash`). A leaked database does not leak usable tokens. SHA-256 (not bcrypt) is correct here because the input is a 256-bit random value — there is nothing to brute-force, and lookups need a deterministic hash to hit the unique index. Say this in the study guide; it is the most commonly misapplied rule in this area.
- **Send:** `Authorization: Bearer tt2_...` on every `/api` request. The `ApiTokenHandler` hashes the presented token, looks up `token_hash`, updates `last_used_at`, returns the user badge.
- **Revoke:** `DELETE /api/tokens/{id}` (own tokens only) — hard delete; the very next request with that token is 401. `GET /api/tokens` lists your tokens (id, name, createdAt, lastUsedAt — never the token value, which the server no longer knows).

## 4. Authorization — the permission matrix and its voters

Every rule below is enforced by a voter via `denyAccessUnlessGranted()` / `#[IsGranted]`. **No inline role `if`s in controllers or services** — centralizing "can this user do this" is the lesson. Voters share one collaborator, `TeamMembershipResolver` (wraps the membership repository, one query, per-request memoized), so the membership lookup lives in exactly one place.

**Matrix** (rows = action, columns = relationship to the resource's team):

| Action | Non-member | Member | Team admin |
|---|---|---|---|
| Create a team | anyone authenticated (creator auto-added as admin) | — | — |
| View team, members, tasks, comments | **404** | yes | yes |
| Rename team | 404 | 403 | yes |
| Delete team | 404 | 403 | yes |
| Add/remove members, change roles | 404 | 403 (except: may remove **self** = leave) | yes (except demoting/removing the **last admin** → 422) |
| Create task / comment | 404 | yes | yes |
| Edit task (any field) | 404 | yes | yes |
| Delete task | 404 | only if creator, else 403 | yes |
| Edit comment | 404 | only author, else 403 | only own (admins moderate by delete, not edit) |
| Delete comment | 404 | only author, else 403 | yes (moderation) |
| List/revoke API tokens | own only — someone else's token id is 404 | | |

**Voters:**

- `TeamVoter`: `TEAM_VIEW`, `TEAM_EDIT`, `TEAM_DELETE`, `TEAM_MANAGE_MEMBERS`, `TEAM_CREATE_TASK`
- `TaskVoter`: `TASK_VIEW`, `TASK_EDIT`, `TASK_DELETE`, `TASK_COMMENT` (view/edit/comment delegate to membership; delete additionally allows the creator)
- `CommentVoter`: `COMMENT_EDIT` (author only), `COMMENT_DELETE` (author or team admin)

**401 vs 403 vs 404 policy (pinned):**

- **401** — you are not authenticated: missing/invalid/revoked Bearer token on `/api` (on the web firewall this is the 302-to-`/login`).
- **404** — the resource does not exist **or exists in a team you are not a member of**. Controllers check the `*_VIEW` attribute first and convert a denial into `NotFoundHttpException`. *Justification:* returning 403 to outsiders confirms the resource exists, turning sequential ids into an oracle for other teams' activity (existence, growth rate). Hiding existence is the standard choice for tenant-scoped resources (GitHub does this for private repos). The cost — a confusing 404 when you're accidentally using the wrong account — is acceptable and worth teaching explicitly.
- **403** — you are authenticated *and* allowed to see the resource, but not to do this to it (member renaming the team, non-author editing a comment). 403s are only ever shown to team members, so they leak nothing.

## 5. API contract

### Conventions

- Base URL `http://localhost:8081`. JSON in/out. Error shape identical to part 1, rendered by the same-style `kernel.exception` listener (extended to map `AccessDeniedException` → 403 in the shape):

  ```json
  { "errors": [ { "field": "title", "message": "This value should not be blank." } ] }
  ```

  422 for validation failures (field set), 400 for malformed JSON (field null), 401/403/404/405/429/500 all in the same shape.
- Resource representations (used consistently by all success bodies):
  - **User (public):** `{ "id", "email", "displayName" }` (email only shown to fellow team members / self)
  - **Team:** `{ "id", "name", "createdAt", "myRole" }`
  - **Member:** `{ "user": <User>, "role", "joinedAt" }`
  - **Task:** `{ "id", "teamId", "title", "description", "status", "priority", "assignee": <User>|null, "dueDate": "2026-08-01"|null, "createdBy": <User>, "createdAt", "updatedAt" }`
  - **Comment:** `{ "id", "taskId", "author": <User>, "body", "createdAt" }`
  - Dates: ATOM UTC for timestamps, `Y-m-d` for `dueDate`.

### Endpoints

**Auth & tokens** — `RL:auth` = auth rate limit applies (§6)

| Method & path | Auth | Success | Errors |
|---|---|---|---|
| `POST /api/register` `{email, password, displayName}` | public, RL:auth | 201 User | 422, 400, 429 |
| `POST /api/tokens` `{email, password, name}` | public, RL:auth | 201 `{id, name, token, createdAt}` — plain token, once | 401 `"Invalid credentials."` (deliberately identical for unknown email and wrong password — no user enumeration), 422, 429 |
| `GET /api/me` | token | 200 User | 401 |
| `GET /api/tokens` | token | 200 `{tokens:[...]}` (no plain values) | 401 |
| `DELETE /api/tokens/{id}` | token | 204 | 401, 404 (not yours / unknown) |
| `GET /login` | public | 200 Twig form | |
| `POST /login` (form) | public, RL:auth | 302 → `/dashboard` | 302 → `/login` + flash error, 429 |
| `POST /logout` | session | 302 → `/login` | |

**Teams & membership** — all require auth (401 otherwise); `RL:write` on every POST/PATCH/DELETE

| Method & path | Voter | Success | Errors |
|---|---|---|---|
| `POST /api/teams` `{name}` | authenticated only | 201 Team (creator = admin) | 422 |
| `GET /api/teams` | — (query scoped to caller) | 200 `{teams:[...]}` | |
| `GET /api/teams/{id}` | TEAM_VIEW → 404 | 200 Team | 404 |
| `PATCH /api/teams/{id}` `{name}` | TEAM_EDIT | 200 Team | 403, 404, 422 |
| `DELETE /api/teams/{id}` | TEAM_DELETE | 204 (cascades) | 403, 404 |
| `GET /api/teams/{id}/members` | TEAM_VIEW → 404 | 200 `{members:[...]}` | 404 |
| `POST /api/teams/{id}/members` `{email, role}` | TEAM_MANAGE_MEMBERS | 201 Member | 403, 404, 422 (unknown email, already a member, bad role) |
| `PATCH /api/teams/{id}/members/{userId}` `{role}` | TEAM_MANAGE_MEMBERS | 200 Member | 403, 404, 422 (`"A team must keep at least one admin."`) |
| `DELETE /api/teams/{id}/members/{userId}` | TEAM_MANAGE_MEMBERS, **or** userId = self | 204 | 403, 404, 422 (last admin) |

**Tasks & comments**

| Method & path | Voter | Success | Errors |
|---|---|---|---|
| `POST /api/teams/{id}/tasks` | TEAM_CREATE_TASK | 201 Task | 403→n/a (members only reach here; outsiders 404), 404, 422 |
| `GET /api/teams/{id}/tasks` | TEAM_VIEW → 404 | 200 (paginated, below) | 404, 422 (bad query params) |
| `GET /api/tasks/{id}` | TASK_VIEW → 404 | 200 Task + `"comments": [...]` (oldest first) | 404 |
| `PATCH /api/tasks/{id}` (any subset of title, description, status, priority, assigneeId, dueDate) | TASK_EDIT | 200 Task | 404, 422 (invalid enum; `assigneeId` not a team member: `"Assignee must be a member of the team."`) |
| `DELETE /api/tasks/{id}` | TASK_DELETE | 204 | 403 (member, not creator), 404 |
| `POST /api/tasks/{id}/comments` `{body}` | TASK_COMMENT | 201 Comment | 404, 422 |
| `PATCH /api/comments/{id}` `{body}` | COMMENT_EDIT | 200 Comment | 403 (not author), 404, 422 |
| `DELETE /api/comments/{id}` | COMMENT_DELETE | 204 | 403 (member, not author, not admin), 404 |

Task creation body: `{title (NotBlank, ≤255), description? (≤10000), status? (enum, default todo), priority? (enum, default medium), assigneeId? (team member), dueDate? (Y-m-d)}` — one `CreateTaskRequest` / `UpdateTaskRequest` DTO pair, Validator constraints, exactly like part 1's `CreateLinkRequest`.

### Pagination / filtering / sorting — `GET /api/teams/{id}/tasks`

| Param | Rule | Default |
|---|---|---|
| `page` | int ≥ 1 | 1 |
| `limit` | int 1–100 | 20 |
| `status` | `todo` \| `in_progress` \| `done` | none (all) |
| `assignee` | user id (`\d+`); the literal `none` selects unassigned | none (all) |
| `sort` | whitelist: `createdAt`, `dueDate`, `priority`, `status` | `createdAt` |
| `direction` | `asc` \| `desc` | `desc` |

Any value outside these rules → **422** in the error shape with `field` = the param name (never a silent fallback — fail loudly is the API-design lesson). Sorting is a whitelist mapped to explicit DQL expressions, **never** interpolated from user input (SQL-injection-shaped thinking even though Doctrine parameterizes). `priority` sorts by rank (high > medium > low), not alphabetically. Response:

```json
{ "tasks": [ <Task>, ... ], "page": 1, "limit": 20, "total": 57, "pages": 3 }
```

Offset pagination (`LIMIT/OFFSET` via Doctrine's `Paginator`) — cursor pagination is named in the study guide as the scaling follow-up, not built.

## 6. Rate limiting (Redis)

`symfony/rate-limiter` with the Redis-backed storage (`cache.rate_limiter` pool on the `redis` container). Two named limiters, **sliding window** policy (smoother than fixed window at boundaries — teach why: fixed window allows 2× the limit straddling a boundary):

| Limiter | Applies to | Key | Limit (dev/prod) | Limit (`when@test`) |
|---|---|---|---|---|
| `auth` | `POST /api/register`, `POST /api/tokens`, `POST /login` | client IP | **5 / minute** | 10 000 / minute |
| `api_write` | every other `POST`/`PATCH`/`DELETE` under `/api` | authenticated user id (IP if somehow anonymous) | **30 / minute** | 10 000 / minute |

Enforcement: one `RateLimitListener` with two `kernel.request` hooks — the `auth` hook runs **before** the security firewall (keyed by IP, so failed logins count and `POST /login` is seen before form_login handles it), the `api_write` hook **after** it (keyed by the authenticated user id) — each selecting the limiter by route/method and calling `consume(1)`. On rejection it throws `TooManyRequestsHttpException($retryAfter)` — the existing exception listener renders it, so 429 automatically has the standard shape plus `Retry-After: <seconds>`:

```json
{ "errors": [ { "field": null, "message": "Too many requests. Retry after 42 seconds." } ] }
```

**Test env:** the relaxed `when@test` limits keep Codeception deterministic (suites must never flake on shared limiter state). The 429 path itself is covered in the **Unit** suite: `RateLimitListener` with an in-memory storage and a limit of 2, asserting the exception, the seconds, and the header.

**Burst demo (the spec's "demonstrable"):** `scripts/rate-limit-demo.sh` — 7 rapid `POST /api/tokens` attempts with bad credentials against `localhost:8081`, printing status codes: `401 ×5`, then `429` with `Retry-After`, proving both the limit and that failed logins are what's being counted (credential-stuffing is the attack this models).

## 7. Caching (Redis) with explicit invalidation

One tag-aware Redis pool (`tasks.cache` → `RedisTagAwareAdapter`). Two cached hot reads, one tag:

| Cached read | Key | Tag | TTL |
|---|---|---|---|
| Team task list (`GET /api/teams/{id}/tasks`) — serialized response array per query shape | `task_list.{teamId}.{sha1(page\|limit\|status\|assignee\|sort\|direction)}` | `team_tasks.{teamId}` | 60 s |
| Dashboard summary (task counts by status + total, per team) | `team_summary.{teamId}` | `team_tasks.{teamId}` | 300 s |

**Explicit invalidation triggers:** task create, task update (any field), task delete, and team delete each call `$pool->invalidateTags(["team_tasks.{$teamId}"])` — one tag wipes every page/filter/sort variant at once (this is *why* tags: per-key deletion cannot enumerate the query-shape variants). Comments are deliberately **not** part of the cached payloads, so comment writes need no invalidation — shrinking the invalidation surface by shaping the payload is itself the design lesson.

**Stale-data risk being taught, stated in the study guide:** every cache read can be up to TTL stale when an invalidation path is missed — and one is missed *by design*: a user's `displayName` appears inside cached task lists (as assignee/creator), and nothing invalidates on user changes. Harmless here (no profile-edit endpoint), but it demonstrates the failure mode: **invalidation must enumerate every write path that touches the cached data, and the ones you forget are the bug.** TTL is the backstop, not the strategy.

## 8. Twig dashboard + XSS

Server-rendered, session-only (web firewall; anonymous → 302 `/login`; API tokens do **not** work here):

- `GET /dashboard` — the caller's teams with cached summary counts (§7).
- `GET /dashboard/teams/{id}` — the team dashboard: status counts, the task list (default sort), and each task's comments — titles, descriptions, and comment bodies are **user-authored content rendered into HTML**. Access via the same `TEAM_VIEW`-else-404 policy.

**XSS mitigation made concrete:**

- Twig autoescaping stays on (default); templates use `{{ task.title }}` etc. — **no `|raw` anywhere in the project**.
- The Api suite stores a task titled `<script>alert('xss')</script>` and a comment body `<img src=x onerror=alert(1)>` via the API, loads the dashboard with a session, and asserts the response contains `&lt;script&gt;` and does **not** contain the raw `<script>alert` / `onerror=` payloads. The vulnerability is *demonstrated blocked*, not just described.
- Study guide contrast: the JSON API returns the same strings raw with `Content-Type: application/json` — safe *in that context* — which is exactly why "we're just an API" is not an XSS defense the moment anything renders that data into HTML.

## 9. Docker Compose

Project-local `compose.yaml`, name `task-tracker`, mirroring part 1's layout (entrypoint waits for MySQL, composer-installs if `vendor/` missing, migrates `app` **and** `app_test`):

| Service | Image / build | Ports | Notes |
|---|---|---|---|
| `php` | `docker/php/Dockerfile` (php:8.3-fpm + pdo_mysql, intl, opcache, **redis** ext; composer) | none | app bind-mounted |
| `nginx` | nginx:stable-alpine | **8081:80** | proxies to `php:9000` |
| `mysql` | mysql:8 | **unpublished** | named volume, healthcheck, init script creates `app_test` |
| `redis` | redis:7-alpine | **unpublished** | no persistence config (cache + limiter data is disposable — say why) |

**Repo gotcha (must-do):** Ian's global gitignore has an unanchored `.env` rule. `part-2-task-tracker/.gitignore` must contain `!/.env` and `!/.env.*` negations, verified with `git check-ignore` against every committed-intent env file.

## 10. Jenkins — dockerized CI as a gate

Separate compose project in `part-2-task-tracker/jenkins/` (`docker compose -f jenkins/compose.yaml up -d`), so app dev and CI lifecycles are independent. **Zero manual clicking:** everything is code.

- **Image:** built from `jenkins/Dockerfile` — `jenkins/jenkins:lts-jdk17` + `jenkins-plugin-cli` installing a pinned `plugins.txt` (workflow-aggregator, git, configuration-as-code, job-dsl, docker-workflow).
- **Config:** JCasC `casc.yaml` mounted read-only — creates the sole admin user (`admin`/`admin`, local-only toy), disables the setup wizard, and runs a Job DSL seed that defines one pipeline job, `task-tracker`, reading `part-2-task-tracker/Jenkinsfile` from SCM.
- **How the pipeline gets the code:** the repo root is bind-mounted read-only into the Jenkins container at `/repo`; the seed job copies the current `Jenkinsfile` from there and `load`s it on every build, and the pipeline rsyncs the **working tree** into an isolated CI workspace. (As designed this sketched a `git clone` from `file:///repo`, but a clone only ships *committed* work — the gate must judge the working tree before it is committed; see the README's CI section.) Port **8082:8080**.
- **How stages run:** the Jenkins container mounts `/var/run/docker.sock` and has the docker CLI; the Jenkinsfile runs each stage inside the app's own php image (`docker-workflow`), so CI uses the identical runtime as dev — no toolchain drift, nothing on the "host".

**Pipeline stages (each red-fails the build):**

1. `composer validate --strict` + `composer install`
2. `vendor/bin/php-cs-fixer fix --dry-run --diff` (config `.php-cs-fixer.dist.php`, `@Symfony` ruleset)
3. `vendor/bin/phpstan analyse` (level 8, `src/` + `tests/`, `phpstan.dist.neon`)
4. Codeception: `docker compose -p task-tracker-ci -f compose.yaml -f compose.ci.yaml up -d` (separate project name, **no published ports** — never collides with dev on 8081 or part 1 on 8080), `exec -T php vendor/bin/codecept run`, `down -v` in a `post { always }`.

**"Green" means:** manifest valid, zero style drift, zero static-analysis errors, all Api + Unit tests passing against real MySQL + Redis. **CI-as-gate narrative:** the pipeline is the definition of done — a red build blocks merge (solo-repo discipline: nothing lands on `main` while red; the study guide maps this to branch protection + required status checks in a team setting). Demonstrated concretely: introduce a deliberate PHPStan error on a branch, watch the build go red, revert, green.

## 11. SOLID refactor (deliberate, documented)

**The accretion point (predicted, and allowed to happen):** the task-list read path. By the end of milestone 2, `TaskController::list()` will genuinely have accreted: query-param parsing and validation, the sort whitelist, pagination math, the Doctrine query build, cache-key construction, cache read-through, and response mapping — because each milestone added "just one more thing" to the same method. This is not staged; it is the natural first implementation.

**The refactor (milestone 3):** extract

- `TaskListQuery` — immutable DTO of validated page/limit/filters/sort (parsing+validation moves behind a named constructor `fromRequest()`);
- `TaskListProviderInterface` with `DoctrineTaskListProvider` (query + count only);
- `CachingTaskListProvider` — a **decorator** implementing the same interface, owning key/tag/TTL, wired via DI so the controller depends only on the interface.

Principles exercised, by name: **SRP** (HTTP parsing, querying, and caching become three objects), **OCP/DIP** (caching added by decoration behind an abstraction, not by editing the query code; swap-out visible in `services.yaml`). Documented before/after — real diffs, the "why", and what the controller shrank to — in `part-2-task-tracker/docs/solid-refactor.md`.

## 12. Test plan (Codeception, inside the `php` container, `test` env, `app_test` DB, relaxed limiters)

**Api suite** (`tests/Api`): register (201, 422 dupes/short password, no-enumeration 401 on login); token lifecycle (issue → use → list hides value → revoke → 401); 401 on every protected route without a token; **the full §4 matrix as a test grid** — for team/task/comment endpoints: outsider→404, member-over-privilege→403, admin→2xx, incl. last-admin 422 and leave-team; task CRUD happy paths; pagination/filter/sort (page math, `total`, each filter, each sort key incl. priority ranking, 422 on bad `sort`/`status`/`limit`); assignee-must-be-member 422; cache invalidation observable via API (write → immediately-fresh list read); dashboard XSS assertions (§8) with a form-login session; 404/405 cross-cutting in the error shape.

**Unit suite** (`tests/Unit`): the three **voters** (pure grants/denies across role × relationship — in-memory entities, no DB: authorization logic is the pure-logic star of this part, exactly the pyramid slot `CodeGenerator` filled in part 1); `RateLimitListener` 429 + Retry-After with in-memory storage; `TaskListQuery::fromRequest()` validation table; cache key builder determinism.

## 13. Milestones

Three stages (matching the build plan), each ending in a working, committable state.

**Stage 1 — foundation: scaffold, entities, auth, voters, CRUD, tests**

1. Scaffold + Docker: skeleton, `compose.yaml` (php/nginx/mysql/redis), entrypoint, `.gitignore` with the `!/.env` negations (verified via `git check-ignore`). Done: default page on 8081; part 1 still healthy on 8080.
2. Entities + migrations: all six tables, indexes, FKs. Done: migrations run on `app` and `app_test`.
3. Auth: registration, password hashing, `ApiTokenHandler`, both firewalls, token issue/list/revoke. Done: curl register → token → `GET /api/me`.
4. Teams + membership endpoints with `TeamVoter` + 404 policy + last-admin rule.
5. Tasks + comments CRUD with `TaskVoter`/`CommentVoter`; pagination/filter/sort contract.
6. Api + Unit suites green (everything in §12 except rate-limit/cache/XSS items).

**Stage 2 — Redis + Twig: rate limiting, caching, dashboard/XSS**

7. Rate limiting: limiters, listener, 429 shape, `when@test` relaxation, `scripts/rate-limit-demo.sh`. Done: scripted burst shows 401×5 → 429.
8. Caching: tag-aware pool, both cached reads, invalidation on task/team writes; freshness Api tests. Done: `redis-cli MONITOR` shows hits; write → fresh read.
9. Twig: login form, `/dashboard`, `/dashboard/teams/{id}`, XSS payload tests. Done: stored `<script>` renders inert, test proves it.

**Stage 3 — quality gate: CS-Fixer, PHPStan, Jenkins, SOLID refactor**

10. PHP-CS-Fixer + PHPStan level 8 configured and passing locally (in-container).
11. Jenkins: `jenkins/` compose, JCasC, seed job, Jenkinsfile, isolated CI compose project. Done: fresh `docker compose up` of Jenkins → one click "Build Now" → green; deliberate red demonstrated.
12. SOLID refactor (§11) executed with `docs/solid-refactor.md`; pipeline green after.
13. README + study-guide polish; final pass against the spec's "done when".
