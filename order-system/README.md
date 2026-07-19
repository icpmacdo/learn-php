# Order system — part 3 of the PHP curriculum

An e-commerce order platform built as a **DDD modular monolith**: one
deployable, four bounded contexts (Catalog, Ordering, Inventory,
Notification) as Symfony modules, with the domain layer importing **zero
framework code** — enforced mechanically by deptrac, in CI.

Spec: [`../docs/specs/order-system.md`](../docs/specs/order-system.md) ·
PRD: [`../docs/prds/order-system.md`](../docs/prds/order-system.md)

## Running

Everything runs in Docker; nothing is installed on the host.

```sh
docker compose up -d --wait                       # API on http://localhost:8083
docker compose -f jenkins/compose.yaml up -d --wait   # Jenkins on http://localhost:8084 (admin/admin)
```

The php entrypoint installs Composer dependencies when `vendor/` is missing,
waits for MySQL, and migrates the dev (`app`) and test (`app_test`)
databases. Ports: API **8083**, Jenkins **8084**. Parts 1–2 own 8080–8082
and keep running untouched.

All tooling runs inside the `php` container:

```sh
docker compose exec php vendor/bin/codecept run Unit
docker compose exec php vendor/bin/codecept run Integration
docker compose exec php vendor/bin/codecept run Acceptance
docker compose exec php vendor/bin/deptrac                     # architecture law
docker compose exec php vendor/bin/phpstan analyse             # level 8, no baseline
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run --diff
docker compose exec php bin/console doctrine:migrations:migrate
```

## Domain tour — four contexts, three "products"

The same word means a different object in every context, and that is the
whole bounded-context lesson:

| Context | Its model | Knows | Deliberately does NOT know |
|---|---|---|---|
| **Catalog** | `Product` | SKU, name, description, price, active | stock levels, who ordered it |
| **Inventory** | `StockItem` + `Reservation` | SKU, on-hand, reserved | price, name — a warehouse doesn't care what it costs |
| **Ordering** | `Cart`, `Order` + `OrderLine` | SKU, name+price *snapshotted at placement* | the current catalog price — a later price change must never rewrite a past order |
| **Notification** | `EmailMessage` only | whatever the events carry | any live product/stock/order state |

The only things all four agree on are the shared-kernel value objects
(`src/Shared/Domain`): `Money` (int minor units + currency — never a float,
not even in JSON), `Sku`, `Quantity` (1–99), `CustomerId`, a `Clock` port,
and the domain-event plumbing.

Key invariants live in aggregates, not services:

- **`Order` state machine** — the only legal transitions are
  `placed → paid`, `placed → cancelled`, `paid → shipped`, and
  `paid → cancelled` *iff* now < paidAt + 24h (refund window, checked
  against the injected `Clock`). Total is computed from immutable lines,
  never stored. Mutation only through `place()/pay()/ship()/cancel()`.
- **`StockItem`** — `0 ≤ reserved ≤ onHand` always; reserving beyond
  availability throws `InsufficientStock`; shipping commits (both drop).
- **`Reservation`** — exactly one per order (unique index), released or
  committed **at most once** — the seed of part 4's idempotency lesson.
- **`Cart`** — SKUs and quantities only, **no prices**: prices resolve live
  through the `ProductCatalog` ACL port and freeze only at checkout (a cart
  held a week checks out at *today's* price; the order then snapshots it).

Invariants also hold under **concurrency**: every guard above is
check-then-act in PHP, so the mutation paths load their aggregate through a
locking repository read (`bySkuForUpdate` / `byIdForUpdate` /
`byCustomerForUpdate` — `SELECT … FOR UPDATE` inside the command's
transaction). Two checkouts racing for the last unit serialize on the stock
row (one gets the 409); two devices checking out one cart serialize on the
cart row (the loser sees it consumed: 422); a cancel committing while a
payment's gateway call is in flight makes the payment's locked re-read throw
409 instead of overwriting `cancelled` with `paid`. A DB `CHECK
(0 <= reserved <= on_hand)` backstops S1 the same way R1's unique index
backstops the reservation — the belt under the braces. Both races are
exercised for real over HTTP in `tests/Acceptance/ConcurrentCheckoutCest.php`
(curl_multi against nginx) and deterministically in the integration suite
(`StockConcurrencyCest`, `PaymentRaceCest`).

### The event seam

Aggregates record events; Application handlers dispatch them through the
`DomainEventDispatcher` port after persisting; subscribers in other contexts
consume them — all in-process, synchronously, **inside the same DB
transaction** as the command:

```
OrderPlaced    -> Inventory reserves stock   (InsufficientStock rolls the WHOLE checkout back -> 409)
               -> Notification logs the confirmation email
               -> read_order_summary row inserted
OrderPaid      -> Notification logs the receipt          -> summary status
OrderShipped   -> Inventory commits the reservation      -> Notification shipment notice -> summary
OrderCancelled -> Inventory releases the reservation     -> summary
Catalog + Inventory events -> read_product_list projector
```

That same-transaction guarantee is exactly what part 4 takes away (outbox,
async consumers, compensation) — the port stays, the adapter changes.

The one synchronous cross-context *call* is Ordering → Catalog: an
anti-corruption port (`ProductCatalog`, defined in Ordering's Domain,
returning Ordering's own `ProductSnapshot`) whose adapter calls Catalog's
published `ProductCatalogQuery`. Ordering never touches Catalog entities.

## The architecture law (deptrac)

`deptrac.yaml` is an **allowlist**; anything not listed is a violation and
red-fails CI's deptrac stage:

- `SharedDomain` depends on nothing.
- Each context's `Domain` sees only `SharedDomain` + its own `Event/` layer —
  **no vendor code**: one `use Doctrine\...` in an entity goes red (hence
  Doctrine XML mappings in `config/doctrine/`; entities are pure PHP).
- `Application` adds *other contexts' Event layers only* — integration is
  events, not internals. Handlers are plain PHP; no framework here either.
- Only `Infrastructure` sees Symfony/Doctrine; Ordering's infra may
  additionally call `Catalog\Application\PublicApi` (the ACL adapter's one
  legal cross-context call).

**See it fail** (30 seconds): give `src/Ordering/Domain/Order.php` a typed
property using a vendor class —

```php
use Doctrine\ORM\EntityManagerInterface;   // inside Order.php
private ?EntityManagerInterface $em = null;
```

— then `docker compose exec php vendor/bin/deptrac`: 1 violation, exit 1,
CI stage red. Revert, and it's green again. (deptrac counts real code
references — a bare unused `use` import is not enough to trip it.)

The hexagonal payoff is visible in `config/services.yaml`: every port→adapter
binding is one line. Swapping `FakePaymentGateway` for a real PSP adapter —
or `LoggingMailer` for real SMTP — touches Infrastructure and that one line,
never the domain.

## API

Base `http://localhost:8083` — JSON, errors always
`{"errors":[{"field","message"}]}`, money always
`{"amountMinor": int, "currency": "EUR"}` (never a float).
Customer endpoints require an `X-Customer-Id` header (1–64 chars; missing →
401 — the header IS part 3's whole auth story, on purpose). Status rule,
pinned: **422** = the request itself is invalid; **409** = valid request,
conflicting state (stock, state machine, refund window).

**Catalog & stock** (admin-ish: no header)

| Endpoint | Purpose |
|---|---|
| `POST /api/products` | add product |
| `GET /api/products?page&limit` | listing with price + availability — served from the `read_product_list` projection |
| `GET /api/products/{sku}` · `PATCH /api/products/{sku}` | detail (write model) / update (name, price, active) |
| `PUT /api/stock/{sku}` `{onHand}` · `GET /api/stock/{sku}` | stock level → `{sku,onHand,reserved,available}` |

**Cart** (customer)

| Endpoint | Purpose |
|---|---|
| `GET /api/cart` | `{lines:[{sku,name,unitPrice,quantity,lineTotal}], total}` — prices resolved **live** via the Catalog ACL (carts store no prices; `total` is `null` when empty) |
| `PUT /api/cart/lines/{sku}` `{quantity}` | create/replace a line (qty 1–99, max 50 lines, one currency per cart; unknown/inactive product → 422) |
| `DELETE /api/cart/lines/{sku}` | remove a line (404 if absent) |

**Orders** (customer unless marked ⚙; another customer's order id → 404, existence hidden)

| Endpoint | Purpose |
|---|---|
| `POST /api/orders` | checkout: consumes the cart, freezes name+price into order lines, **reserves stock in the same transaction** → 201 `placed` · 422 empty cart · 409 `"Insufficient stock for <sku>."` (whole checkout rolls back) |
| `GET /api/orders` | history, newest first — served from the `read_order_summary` projection: `{orders:[{id,status,total,lineCount,placedAt,updatedAt}]}` |
| `GET /api/orders/{id}` | detail: lines, computed total, status, timestamps (write model) |
| `POST /api/orders/{id}/payment` `{paymentMethodToken}` | pay → 200 `paid` · 409 not placed · 422 declined/unknown token · 502 timeout/gateway error — **every failure leaves the order placed-and-payable, stock still reserved** |
| `POST /api/orders/{id}/cancel` | free while `placed`; while `paid` only within **24h of paidAt** (else 409 `"Refund window has closed."`); releases reserved stock · 409 if shipped/cancelled |
| ⚙ `POST /api/orders/{id}/ship` | mark a `paid` order shipped: commits stock out of the warehouse (`onHand` and `reserved` both drop) · 409 if not paid |
| ⚙ `GET /api/notifications?orderId=` | the notification log for one order: `{notifications:[{type,customerId,orderId,subject,createdAt}]}` · 422 missing/non-UUID orderId |

Fake gateway tokens (test-controllable over plain HTTP, attempts persisted
per order): `tok_success` · `tok_declined` (422) · `tok_timeout_once` (502
on the first attempt per order, approves on retry) · `tok_error` (always
502) · anything else → 422.

Decided edge, documented: a cart line whose product goes unknown/inactive —
or is re-priced into another **currency** than the cart's — after it was
added is *omitted* from `GET /api/cart` (a GET stays renderable; a mixed-
currency total is unpriceable) but makes checkout fail loudly with 409
`"Product <sku> is no longer available."` — checkout never silently drops a
line. The line stays in the cart and can still be `DELETE`d.

Notifications are **logged, never sent**: each would-be email is a
`notification_log` row *and* a line on the dedicated `notification` monolog
channel — `grep notification. var/log/dev.log`, or tail
`var/log/notification.log`.

## CQRS-lite — where it earns its keep, and where it's ceremony

Two read models, both projected **synchronously in the same transaction** as
the write (`Projection/` classes in the owning context's Application layer,
DBAL adapters behind ports):

- **`read_product_list`** (`GET /api/products`) — earns its keep because it
  composes **two contexts** without a cross-boundary join at request time:
  Catalog events own name/price/active, Inventory stock events own
  `available` (every stock event carries the new availability in its
  payload, so the projector never looks anything up). Before stage 3 this
  listing was a SQL `LEFT JOIN` onto `inventory_stock_item` — a boundary
  violation deptrac cannot see because it lived in SQL, not in class
  dependencies. The projector moved that composition to write time and made
  the read a single-table `SELECT`.
- **`read_order_summary`** (`GET /api/orders`) — earns its keep because
  history is a flat status/total listing that shouldn't hydrate full `Order`
  aggregates, lines and all, to render a table.

**The honest ceremony note:** in a monolith on one schema, both of these
*could* be joins — the projection mechanics, not the performance, are the
lesson. And to prove you don't project everything: product **detail** and
order **detail** deliberately read the write model. CQRS pays rent only once
read and write shapes truly diverge or move to different stores — which is
precisely what part 4 does to these projections (eventually consistent,
rebuilt from events).

One deliberate non-event: `Cart` emits nothing. It has no consumers, and an
event nobody listens to is ceremony too.

## Tests — the pyramid

All three suites run inside the `php` container (`vendor/bin/codecept run`):

- **Unit** (`tests/Unit`) — pure domain, no container, no DB: `Money`/`Sku`/
  `Quantity` validation tables, **the full `Order` transition table** (every
  legal and illegal transition, refund window at/inside/past the 24h edge
  with a fixed `Clock`), cart rules, stock arithmetic, reservation
  once-only guard, notification message composition. Refund-window *expiry*
  lives here because HTTP tests cannot time-travel.
- **Integration** (`tests/Integration`, real MySQL `app_test`, rolled back
  per test) — Doctrine adapters round-trip every aggregate (the likeliest
  breakage of the pure-entities-via-XML move); the event seam end to end:
  checkout reserves stock + logs the confirmation + projects the summary in
  one transaction, and `InsufficientStock` rolls all of it back; fake
  gateway token behaviors incl. the persisted `tok_timeout_once` counter;
  both projections stay consistent through place/pay/ship/cancel.
- **Acceptance** (`tests/Acceptance`, real HTTP against nginx) — the spec's
  done-when: full lifecycle with stock movements visible via the API, every
  payment failure mode with retry, cancellation with stock release,
  insufficient-stock atomicity, cross-customer 404s, and the notification
  log asserted over `GET /api/notifications`.

## CI — Jenkins with an architecture gate

`jenkins/` is a self-contained compose project (JCasC — `casc.yaml` defines
the admin user and seeds the one `order-system` pipeline job as code; zero
setup clicks). The pipeline (`Jenkinsfile`, loaded fresh from the mounted
repo on every build, so it judges the **working tree** before anything is
committed):

1. Snapshot the working tree into `/private/tmp/order-system-ci`
2. `composer validate --strict` + install
3. PHP-CS-Fixer (dry run — CI vetoes drift, never rewrites)
4. PHPStan (level 8, no baseline)
5. **Deptrac — the architecture gate.** The spec's done-when as a build
   verdict; see "See it fail" above.
6. Codeception, all three suites, against an **isolated** compose project
   (`order-system-ci`: own MySQL and nginx, no published ports), torn down
   win or lose.

Every stage runs in the app's own `order-system-php` image as a sibling
container (docker-outside-of-docker via the mounted socket) — CI's runtime
is byte-identical to dev's.

## Layout (hexagonal, per context)

```
src/
├── Kernel.php            # the one framework touchpoint at root (excluded from deptrac)
├── Shared/
│   ├── Domain/           # Money, Sku, Quantity, CustomerId, Clock, UuidV7,
│   │                     #   DomainEvent + RecordsEvents + dispatcher port,
│   │                     #   TransactionBoundary, StateConflict
│   └── Infrastructure/   # SystemClock, Symfony dispatcher adapter, DBAL
│                         #   types, JSON error plumbing, transactions
├── Catalog/              # Product aggregate · PublicApi query · ProductListProjector
├── Inventory/            # StockItem + Reservation aggregates · event subscribers
├── Ordering/             # Cart + Order aggregates, state machine, payment
│                         #   port (fake gateway), ProductCatalog ACL,
│                         #   OrderSummaryProjector
└── Notification/         # EmailMessage + Mailer port · three subscribers ·
                          #   LoggingMailer (logged, never sent)
    └── each: Domain/ · Application/ · Infrastructure/
```

## What part 4 breaks, on purpose

- The same-transaction event seam (reserve-or-rollback) → outbox, async
  consumers, compensation.
- Synchronously consistent projections → eventual consistency, rebuilds.
- The at-most-once reservation guard (R2) → real idempotency under retries.
- One schema with context-prefixed tables and zero cross-context FKs → the
  fault line along which the monolith gets cut.
