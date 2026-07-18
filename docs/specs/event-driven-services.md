# Part 4 — Order system: event-driven services (act II)

## Goal

Split part 3 along its bounded-context seams into independently running services, and feel exactly what the split costs and buys — the actual senior-engineer lesson about microservices.

## What it is

Three services, each its own Symfony app with its own database, one Docker Compose orchestrating all of them:

- **orders-service** — Ordering + Catalog
- **inventory-service** — stock and reservations
- **notification-service** — sends (logs) customer messages

Communication is async: domain events published via Symfony Messenger over a Redis Streams transport. No service calls another synchronously for its core flow.

## Stack exercised

Symfony Messenger, Redis Streams, the outbox pattern, idempotent consumers, retries + dead-letter queues, correlation IDs across services, event schema versioning, a process manager (saga) coordinating order fulfillment, multi-service Docker Compose.

## Learning objectives

- The outbox pattern: why "save to DB and publish" is two writes and how to make it safe.
- Idempotency: every consumer survives receiving the same event twice.
- Eventual consistency: what the UI shows while inventory hasn't confirmed yet.
- Failure handling: a consumer dies mid-flow, the system recovers, nothing is lost.
- The honest trade-off ledger: what got worse (debugging, consistency, ops) vs. better (independent deploys, isolation) — written down at the end.

## Out of scope

Kubernetes, service mesh, gRPC, distributed tracing infrastructure (correlation IDs in logs are enough here). Deployment waits for part 5.

## Done when

Placing an order in orders-service reserves stock and triggers a notification asynchronously; killing any consumer mid-flow and restarting it loses no data and duplicates no side effects — proven by a scripted chaos test in the Codeception suite.
