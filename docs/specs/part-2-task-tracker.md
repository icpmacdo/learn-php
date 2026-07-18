# Part 2 — Team task tracker (ramp-up)

## Goal

A production-shaped single service. Everything part 1 skipped: real auth, Redis, security hardening, and a CI pipeline acting as a quality gate.

## What it is

A multi-user task tracker API: users belong to teams, teams have tasks, tasks have comments.

- Registration + login (session for the browser, API tokens for clients)
- Roles: team member vs. team admin, enforced with Symfony voters
- Task CRUD with pagination, filtering, and sorting
- Rate limiting (Redis) on auth and write endpoints
- Caching of hot reads (Redis) with explicit invalidation
- One server-rendered Twig page (team dashboard) to make XSS mitigation concrete

## Stack exercised

Symfony Security component, Redis (cache + rate limiter), Twig, Jenkins (dockerized) running composer validate → PHP-CS-Fixer → PHPStan → Codeception, everything from part 1.

## Learning objectives

- Authentication vs. authorization, and where each lives in Symfony.
- Voters: centralizing "can this user do this" instead of scattering ifs.
- XSS in practice: output encoding, JSON vs. HTML contexts, why APIs aren't automatically safe.
- Redis as cache and as rate limiter — two patterns, one tool.
- SOLID applied: at least one deliberate refactor documented before/after.
- CI as a gate: a red pipeline blocks merge, and why that discipline matters.

## Out of scope

Microservices, async messaging, deployment. DDD vocabulary waits for part 3.

## Done when

Jenkins pipeline is green; auth and permission flows are covered by tests; the rate limiter is demonstrable with a scripted burst; the SOLID refactor is documented.
