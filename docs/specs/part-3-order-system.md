# Part 3 — Order system: DDD modular monolith (act I)

## Goal

DDD stops being vocabulary and becomes design pressure you can feel. A domain with genuine business rules, built as a modular monolith with clean boundaries — the same boundaries part 4 will cut along.

## What it is

A small e-commerce order platform, one deployable, four bounded contexts as Symfony modules:

- **Catalog** — products, prices
- **Ordering** — cart, order placement, order state machine (placed → paid → shipped / cancelled), refund window
- **Inventory** — stock levels, reservation on order placement, release on cancellation
- **Notification** — order confirmation / shipment emails (logged, not actually sent)

Payment goes through a faked gateway with realistic failure modes.

## Stack exercised

Everything so far, plus: tactical DDD (entities, value objects like `Money` and `Sku`, aggregates, repositories, domain events), hexagonal architecture (ports & adapters — the domain layer imports no framework code), CQRS-lite (separate read models for listings), in-process event dispatch, Codeception unit + integration + acceptance suites.

## Learning objectives

- Bounded contexts: why Inventory's "product" and Catalog's "product" are different objects.
- Aggregates and invariants: the Order enforces its own rules; nothing edits it from outside.
- Value objects: why `Money` is not a float, ever.
- Domain events as the seam between contexts — the load-bearing prep for part 4.
- Hexagonal architecture: swapping the payment adapter without touching the domain.
- Where CQRS earns its keep and where it's ceremony.

## Out of scope

Separate services, async messaging, deployment. All events are in-process and synchronous.

## Done when

The domain layer has zero framework imports (enforced by a deptrac rule in CI); the full order lifecycle — including payment failure and cancellation with stock release — is covered by acceptance tests.
