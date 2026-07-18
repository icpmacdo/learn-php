# Curriculum

Five parts. Parts 1–2 are standalone warm-ups; parts 3–5 are one system — an e-commerce order platform — that evolves from DDD monolith to event-driven services to a production deployment, mirroring how real systems grow.

| Part | Project | Focus |
|------|---------|-------|
| 1 | [Link-shortener API](specs/link-shortener.md) | Symfony fundamentals, Doctrine/MySQL, REST, Docker, first tests |
| 2 | [Team task tracker](specs/task-tracker.md) | Auth, roles, Redis, security hardening, Jenkins CI |
| 3 | [Order system — DDD monolith](specs/order-system.md) | DDD, hexagonal architecture, CQRS-lite, domain events |
| 4 | [Order system — event-driven services](specs/event-driven-services.md) | Microservices, async messaging, outbox, idempotency |
| 5 | [Order system — ship it](specs/ship-it.md) | Ansible, Lightsail, Jenkins CD, zero-downtime deploys, backups |

## Process

Each part goes through the same loop:

1. **Spec** — one page (this directory), agreed before anything else.
2. **PRD** — expanded only when the part is about to start: user stories, API contracts, data model, milestones.
3. **Implementation** — Claude builds it, production quality.
4. **Study** — the part ships with a study guide; Ian reads the code with it.
5. **Exercises** — small modification tasks ("add a cancellation policy") done by Ian, reviewed by Claude.
6. **Quiz** — including *why was it built this way* questions, not just *what does this do*.

## Ground rules

- Everything runs in Docker; nothing installed globally.
- MySQL and Redis run as containers throughout — no managed services.
- Deployment target is a single AWS Lightsail server, not the wider AWS suite.
- Specs stay one page; detail lives in PRDs, written just-in-time.
