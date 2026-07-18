# PRD — Part 1: Link-shortener API

Expands [the one-page spec](../specs/link-shortener.md). The spec is the source of truth for scope; this document nails down the contracts, data model, and build order.

**Project directory:** `link-shortener/` (self-contained: own `compose.yaml`, own `README.md`).

**Stack:** PHP 8.3, Symfony (current stable, minimal skeleton), Doctrine ORM + Doctrine Migrations, MySQL 8 (container only, not published to the host), Symfony Validator, Codeception (API + Unit suites), nginx + php-fpm via Docker Compose. API served at `http://localhost:8080`.

**Out of scope (deliberate):** auth, Redis, caching, CI, pagination, custom short codes, expiry dates, rate limiting, HTML UI.

---

## 1. User stories

1. As an API consumer, I can submit a long URL and get back a short code, so I can share a compact link.
2. As an API consumer, I get a clear validation error (not a 500) when I submit a malformed or non-http(s) URL.
3. As an API consumer, I can list all links with their hit counts, so I can see which links are used.
4. As an API consumer, I can fetch one link's details by its code.
5. As an API consumer, I can delete a link so its code stops redirecting.
6. As a visitor, following `/r/{code}` redirects me to the original URL, and each visit increments that link's hit count.
7. As the learner (Ian), I can run `docker compose up` and have a working API with zero global installs, and run the full Codeception suite inside the containers.

## 2. API contract

### Conventions

- Base URL: `http://localhost:8080`. All request/response bodies are JSON (`Content-Type: application/json`), except the redirect endpoint.
- Every error response, regardless of status code, uses one shape:

  ```json
  { "errors": [ { "field": "url", "message": "This value is not a valid URL." } ] }
  ```

  `field` is the offending request field for validation errors, or `null` for errors not tied to a field (not found, malformed JSON). There is always at least one entry.
- The `Link` resource representation, used by all success bodies:

  ```json
  {
    "code": "aZ3kQ9x",
    "url": "https://example.com/some/long/path",
    "shortUrl": "http://localhost:8080/r/aZ3kQ9x",
    "hits": 4,
    "createdAt": "2026-07-18T14:03:22+00:00"
  }
  ```

  `shortUrl` is derived from the request's scheme/host (idiomatic: generated with Symfony's `UrlGeneratorInterface`), not stored. `createdAt` is ATOM/RFC 3339 in UTC.

### POST /links — create a short link

Request body:

```json
{ "url": "https://example.com/some/long/path" }
```

Validation rules (Symfony Validator constraints on a small `CreateLinkRequest` DTO — pedagogical choice: shows validation as a first-class layer, separate from the entity):

| Rule | Constraint | Error message (Symfony default unless noted) |
|---|---|---|
| `url` present and non-blank | `NotBlank` | "This value should not be blank." |
| Valid absolute URL, scheme `http` or `https` only | `Url(protocols: ['http', 'https'], requireTld: true)` | "This value is not a valid URL." |
| Max length 2048 characters | `Length(max: 2048)` | "This value is too long. It should have 2048 characters or less." |

Responses:

| Case | Status | Body |
|---|---|---|
| Created | **201** | Link resource. `Location: /links/{code}` header set. |
| Validation failure (blank, invalid, wrong scheme, too long) | **422** | Error shape, one entry per violation, `field: "url"` |
| Body is not valid JSON, or `url` key missing entirely | **400** | `{ "errors": [ { "field": null, "message": "Request body must be valid JSON with a \"url\" field." } ] }` (missing key may alternatively surface as a 422 NotBlank violation — either is acceptable; the API test pins whichever the implementation chooses) |

Duplicate target URLs are allowed: posting the same `url` twice creates two links with different codes. (Simplest behavior; deduplication is a possible later exercise.)

### GET /links — list links

No query parameters (no pagination, per scope).

| Case | Status | Body |
|---|---|---|
| Success (including empty) | **200** | `{ "links": [ <Link resource>, ... ] }` — ordered by `createdAt` descending, newest first |

### GET /links/{code} — link details

| Case | Status | Body |
|---|---|---|
| Found | **200** | Link resource |
| Unknown code | **404** | `{ "errors": [ { "field": null, "message": "Link not found." } ] }` |

### DELETE /links/{code}

| Case | Status | Body |
|---|---|---|
| Deleted | **204** | empty |
| Unknown code | **404** | `{ "errors": [ { "field": null, "message": "Link not found." } ] }` |

Delete is a hard delete. Not idempotent-by-response: a second DELETE of the same code returns 404 (fine for REST; the operation is still safe to retry).

### GET /r/{code} — redirect

| Case | Status | Behavior |
|---|---|---|
| Found | **302** | `Location: <stored url>`; body is Symfony's standard small "Redirecting to …" HTML courtesy page (clients follow the header). Hit count incremented **before** the redirect response is returned. |
| Unknown code | **404** | JSON error shape, `"Link not found."` |

- **302 (temporary), not 301:** deliberate — browsers cache 301s aggressively, which would let clients skip the server and break hit counting. Say this in the study guide.
- Increment is done with a single atomic SQL `UPDATE link SET hits = hits + 1 WHERE ...` (DQL update via the repository), not read-modify-write on the entity — pedagogical: shows the lost-update problem and the raw SQL Doctrine emits.

### Cross-cutting errors

| Case | Status |
|---|---|
| Unknown route | 404, JSON error shape |
| Wrong method on a known route (e.g. PUT /links) | 405, JSON error shape |
| Unhandled exception | 500, JSON error shape (no stack trace in prod env) |

Implementation: a single `kernel.exception` listener (or Symfony's ErrorController with a JSON error renderer) converts all `HttpException`s to the error shape. One place, one shape.

## 3. Short-code generation

- **Alphabet:** base62 — `0-9 A-Z a-z` (62 chars). URL-safe with no escaping, case-sensitive.
- **Length:** 7 characters, fixed. 62^7 ≈ 3.5 × 10^12 combinations — collision probability is negligible at learning-project scale, but we handle it anyway because the handling is the lesson.
- **Generation:** `CodeGenerator` service using `random_int(0, 61)` per character (CSPRNG — codes must not be guessable/sequential). Pure logic, no framework dependencies: the unit-test target.
- **Collision handling:** generate → attempt insert → on `UniqueConstraintViolationException` (unique index on `code`), regenerate and retry, max 5 attempts, then 500. The database's unique index is the source of truth, not a pre-check `SELECT` (which would race). Pedagogical: this is the correct pattern and it demonstrates why "check then insert" is a bug.

## 4. Data model

One entity, one table.

**Table `link`** (Doctrine entity `App\Entity\Link`):

| Column | Type | Constraints | Notes |
|---|---|---|---|
| `id` | `BIGINT UNSIGNED` auto-increment | PK | Surrogate key; idiomatic Doctrine identity |
| `code` | `VARCHAR(7)`, charset/collation `ascii_bin` | **UNIQUE index** `uniq_link_code`, NOT NULL | `ascii_bin` makes the column case-sensitive (base62 needs `a` ≠ `A`) and keeps the index small |
| `url` | `VARCHAR(2048)` | NOT NULL | Matches validation max length |
| `hits` | `INT UNSIGNED` | NOT NULL, DEFAULT 0 | |
| `created_at` | `DATETIME` | NOT NULL | Set in the entity constructor, stored UTC. `updated_at` omitted — nothing updates except `hits`, and timestamping hits is noise |

Lookups by `code` (details, delete, redirect) all hit the unique index. No other indexes needed.

Schema is created exclusively through **Doctrine Migrations** (`make:migration` diff → review → `migrations:migrate`), never `schema:update` — migrations discipline from day one.

## 5. Architecture notes (boring on purpose)

- Minimal Symfony skeleton + only the packs needed (orm, validator, maker for dev). No API Platform, no serializer groups magic — controllers return `JsonResponse` built from a small hand-written `LinkResponseMapper` (or the entity's own `toArray`-style method) so serialization is visible.
- One controller class per concern is overkill here; a single `LinkController` (CRUD) + `RedirectController` is fine.
- `LinkRepository` (extends `ServiceEntityRepository`) holds `findOneByCode`, `incrementHits`. `CreateLinkHandler`-style service is *not* needed — controller → validator → repository is the honest size of this app. Keep the `CodeGenerator` as the one standalone service so DI wiring is demonstrated.
- Environments: `dev` for local, `test` for Codeception (own database `app_test`). Same containers.

## 6. Docker Compose

Three services, project-local `compose.yaml`:

| Service | Image / build | Ports | Notes |
|---|---|---|---|
| `php` | Built from `docker/php/Dockerfile` (php:8.3-fpm + pdo_mysql, intl, opcache; composer binary copied in) | none published | App code bind-mounted for the dev loop |
| `nginx` | nginx:stable (official) + site conf | **8080:80** | Proxies to `php:9000` |
| `mysql` | mysql:8 | **not published to the host** | Named volume for data; healthcheck so `php` waits for readiness; creates `app` and (via init script or test bootstrap) `app_test` databases |

All Composer/console/Codeception commands run inside the `php` container (`docker compose exec php ...`). Nothing global.

## 7. Test plan (Codeception)

Both suites run inside the `php` container against the `test` env and the `app_test` MySQL database, reset between tests (Codeception `Db`/Doctrine module cleanup or a `RunProcess` migration step in bootstrap).

### API suite (`tests/Api`) — every endpoint, happy + error paths

- **POST /links:** 201 + resource shape + `Location` header; code is 7 base62 chars; 422 blank url; 422 invalid URL (`not a url`); 422 wrong scheme (`ftp://…`); 422 url > 2048 chars; 400 malformed JSON body; same url twice → two distinct codes.
- **GET /links:** 200 empty list; 200 with several links, newest first, correct hit counts.
- **GET /links/{code}:** 200 known code, full resource; 404 unknown code with error shape.
- **DELETE /links/{code}:** 204 and subsequent GET is 404; 404 unknown code; deleted code's `/r/{code}` is 404.
- **GET /r/{code}:** 302 with `Location` = stored url; hit count is +1 afterward (assert via GET /links/{code}); two redirects → hits = 2; 404 unknown code.
- **Cross-cutting:** unknown route → 404 JSON error shape; wrong method → 405 JSON error shape.

### Unit suite (`tests/Unit`) — pure logic only

- **CodeGenerator:** returns exactly 7 chars; every char in the base62 alphabet; consecutive calls differ (sanity, not a randomness proof).
- **Collision retry logic** (if extracted into a testable collaborator): retries on duplicate, gives up after 5.
- Explicitly *not* unit tested: controllers, repository SQL, validation constraints — those belong to the API suite. This split *is* the test-pyramid lesson.

## 8. Milestones

Ordered; each ends in a working, committable state.

1. **Scaffold + Docker.** `composer create-project symfony/skeleton` (run via a throwaway composer container), `compose.yaml` with php/nginx/mysql, Dockerfile, nginx conf. Done: `docker compose up` serves Symfony's default response on `localhost:8080`.
2. **Entity + migration.** `Link` entity, repository, first migration, run it. Done: `link` table exists in MySQL with the unique index on `code`.
3. **CodeGenerator service + unit suite.** Install/configure Codeception, write the generator, green unit tests. Done: `vendor/bin/codecept run Unit` passes in the container.
4. **POST /links.** DTO + validator + error listener (the single JSON error shape lands here) + collision retry. Done: manual curl create works; 201/422/400 behave per contract.
5. **Read + delete endpoints.** GET /links, GET /links/{code}, DELETE /links/{code}, shared 404 handling. Done: full CRUD via curl.
6. **Redirect.** GET /r/{code} with atomic hit increment, 302/404. Done: browser follow-through increments hits.
7. **API suite.** `test` env + `app_test` DB wiring, full API test list from §7. Done: `vendor/bin/codecept run` all green.
8. **README + polish.** Project README: run instructions, full API reference with curl examples, how to run tests. Final pass against the spec's "done when". Done: fresh-clone `docker compose up` → working API.
