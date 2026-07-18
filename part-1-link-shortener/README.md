# Part 1 — Link-shortener API

A JSON REST API that shortens URLs and counts redirect hits. First project of
the [learn-php curriculum](../docs/specs/part-1-link-shortener.md): small
enough to hold in your head, so every Symfony concept stays visible.

**Stack:** PHP 8.3 (php-fpm) · Symfony 7.4 · Doctrine ORM + Migrations ·
MySQL 8 · Symfony Validator · Codeception · nginx — all in Docker Compose,
nothing installed on the host.

## Running it

```sh
docker compose up -d
```

That is the whole setup. The php container's entrypoint installs the Composer
dependencies if `vendor/` is missing (i.e. on a fresh clone — the first boot
takes a minute or two longer), waits for MySQL to accept connections, runs the
Doctrine migrations for both the dev database (`app`) and the test database
(`app_test`), then starts php-fpm. nginx serves the API on
**http://localhost:8080**. MySQL is deliberately *not* published to the host —
only the containers can reach it.

Every PHP-related command (composer, Symfony console, tests) runs inside the
`php` container:

```sh
docker compose exec php php bin/console about     # Symfony console
docker compose exec php composer install          # composer
```

Tear down with `docker compose down` (add `-v` to also drop the MySQL data
volume and start truly fresh).

## API reference

Base URL `http://localhost:8080`. Everything speaks JSON except the redirect.

The **Link resource** used by all success responses:

```json
{
  "code": "aZ3kQ9x",
  "url": "https://example.com/some/long/path",
  "shortUrl": "http://localhost:8080/r/aZ3kQ9x",
  "hits": 4,
  "createdAt": "2026-07-18T14:03:22+00:00"
}
```

Every error, regardless of status code, uses one shape (`field` is the
offending request field, or `null` when the error isn't tied to a field):

```json
{ "errors": [ { "field": "url", "message": "This value is not a valid URL." } ] }
```

### POST /links — create a short link

```sh
curl -i -X POST http://localhost:8080/links \
  -H 'Content-Type: application/json' \
  -d '{"url": "https://example.com/some/long/path"}'
```

| Case | Status | Notes |
|---|---|---|
| Created | 201 | Link resource; `Location: /links/{code}` header |
| Blank / invalid / non-http(s) / >2048-char URL | 422 | one error entry per violation, `field: "url"` |
| Body not valid JSON, or no `"url"` key | 400 | `field: null` |

Only `http` and `https` URLs are accepted. Posting the same URL twice creates
two links with different codes. Codes are 7 random base62 characters
(`0-9A-Za-z`), generated with a CSPRNG.

### GET /links — list all links

```sh
curl http://localhost:8080/links
```

Returns `200` with `{"links": [...]}`, newest first. No pagination (out of
scope for part 1).

### GET /links/{code} — link details

```sh
curl http://localhost:8080/links/aZ3kQ9x
```

`200` with the Link resource, or `404` with `"Link not found."`.

### DELETE /links/{code}

```sh
curl -i -X DELETE http://localhost:8080/links/aZ3kQ9x
```

`204` with an empty body (hard delete), or `404` for an unknown code — 
including a second delete of the same code.

### GET /r/{code} — redirect

```sh
curl -i http://localhost:8080/r/aZ3kQ9x
```

`302` with `Location: <stored url>`, or `404` (JSON) for an unknown code. The
hit count is incremented before the response goes out, with a single atomic
`UPDATE link SET hits = hits + 1` — not read-modify-write, which would lose
updates under concurrent requests.

Why 302 and not 301: browsers cache 301s aggressively and would skip the
server on repeat visits, silently breaking hit counting.

### Cross-cutting

Unknown routes → 404, wrong method on a known route → 405, unexpected
exceptions → 500 (no stack trace). All in the same JSON error shape, produced
by one `kernel.exception` listener
([`src/EventListener/JsonExceptionListener.php`](src/EventListener/JsonExceptionListener.php)).

The `{code}` routes carry a `requirements: ['code' => '[0-9A-Za-z]+']` regex,
so a path segment that can't possibly be a code (e.g. a short URL mangled in
transit with a smart quote or ellipsis) is a routing-level 404 — same JSON
error shape, but the message is the router's "No route found …" rather than
"Link not found.".

## Running the tests

Both Codeception suites run inside the php container, against the `test`
environment and the separate `app_test` database — dev data is never touched.
Each API test runs in a transaction that is rolled back afterwards.

```sh
# everything
docker compose exec php php vendor/bin/codecept run

# just the API suite (every endpoint, happy + error paths)
docker compose exec php php vendor/bin/codecept run Api

# just the unit suite (CodeGenerator + collision retry, no DB)
docker compose exec php php vendor/bin/codecept run Unit

# one test, with steps printed
docker compose exec php php vendor/bin/codecept run Api RedirectCest --steps
```

After changing suite configuration (`tests/*.suite.yml`), regenerate the actor
traits: `docker compose exec php php vendor/bin/codecept build`.

## Peeking at the raw SQL

A stated learning objective: *see* that Doctrine sends parameterized queries,
so user input never gets concatenated into SQL.

**1. The dev log.** In the `dev` environment Doctrine logs every query with
its parameters separated from the SQL:

```sh
docker compose exec php sh -c 'tail -f var/log/dev.log | grep doctrine'
# then: curl http://localhost:8080/links/aZ3kQ9x
# doctrine.DEBUG: Executing statement: SELECT ... WHERE l0_.code = ? (parameters: array{"1":"aZ3kQ9x"}, ...)
```

The `?` placeholder is the point — the value travels separately as a bound
parameter (a prepared statement), so `'; DROP TABLE link; --` as a code is
just a string that matches nothing.

**2. Print the SQL of any DQL query.** Drop this anywhere (e.g. temporarily in
`LinkRepository::findOneByCode`):

```php
$query = $this->createQueryBuilder('l')
    ->andWhere('l.code = :code')
    ->setParameter('code', $code)
    ->getQuery();

dump($query->getSQL());   // SELECT ... FROM link l0_ WHERE l0_.code = ?
```

**3. General MySQL log** (see everything the server receives, including the
migrations):

```sh
docker compose exec mysql mysql -uroot -proot \
  -e "SET GLOBAL general_log = 'ON'; SET GLOBAL log_output = 'TABLE';"
# ...make some requests...
docker compose exec mysql mysql -uroot -proot \
  -e "SELECT event_time, argument FROM mysql.general_log WHERE command_type='Execute' ORDER BY event_time DESC LIMIT 10\G"
```

## Project tour

| Path | What it is |
|---|---|
| `compose.yaml`, `docker/` | The three services (php, nginx, mysql), entrypoint with migration-on-boot |
| `src/Entity/Link.php` | The one entity; unique index `uniq_link_code` declared here |
| `src/Repository/LinkRepository.php` | All SQL/DQL in one readable place |
| `src/Service/CodeGenerator.php` | Random base62 codes — pure logic, the unit-test target |
| `src/Service/LinkCreator.php` | Insert-and-catch collision retry (why pre-check SELECTs race) |
| `src/Dto/CreateLinkRequest.php` | Validation constraints as a first-class layer |
| `src/Controller/` | `LinkController` (CRUD) + `RedirectController` (302) |
| `src/EventListener/JsonExceptionListener.php` | One place, one JSON error shape |
| `migrations/` | Schema changes — always migrations, never `doctrine:schema:update` |
| `tests/Api/`, `tests/Unit/` | Codeception suites (the test-pyramid split) |
