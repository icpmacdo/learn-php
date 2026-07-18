# Part 1 — Link-shortener API (ramp-on)

## Goal

First contact with Symfony. A project small enough to hold entirely in your head, so every framework concept is visible and nothing is magic.

## What it is

A JSON REST API that shortens URLs and tracks hits.

- `POST /links` — create a short link (validated URL, generated code)
- `GET /links` — list links with hit counts
- `GET /links/{code}` — link details
- `DELETE /links/{code}` — remove a link
- `GET /r/{code}` — redirect to the target and increment the hit count

## Stack exercised

PHP 8, Symfony (routing, controllers, services, dependency injection), Doctrine ORM + MySQL (entities, migrations, repositories), Symfony Validator, Docker Compose (php-fpm, nginx, mysql), Codeception (API + unit suites).

## Learning objectives

- MVC as Symfony implements it: where a request goes, step by step.
- The DI container: why services are wired, not instantiated.
- Doctrine: entity lifecycle, migrations, repository pattern.
- Why parameterized queries make SQL injection a non-issue — and how to see the raw SQL to verify it.
- REST conventions: status codes, error response shape, resource naming.
- The test pyramid: what belongs in a unit test vs. an API test.

## Out of scope

Auth, Redis, caching, CI, pagination. Deliberately.

## Done when

`docker compose up` gives a working API; every endpoint is covered by the Codeception API suite; the README documents the API and how to run the tests.
