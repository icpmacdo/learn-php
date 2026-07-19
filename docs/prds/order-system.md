# PRD — Part 3: Order system (DDD modular monolith)

Expands [the one-page spec](../specs/order-system.md). The spec is the source of truth for scope; this document nails down the ubiquitous language, context map, aggregates and invariants, the event seam, the hexagonal/deptrac law, contracts, and build order. This is the design-heavy part: the PRD is where DDD stops being vocabulary.

**Project directory:** `order-system/` (self-contained: own `compose.yaml`, own `README.md`, own Jenkins compose under `jenkins/`).

**Stack:** PHP 8.3, Symfony (current stable, minimal skeleton), Doctrine ORM + Migrations (XML mappings — see §6), MySQL 8, Codeception (Unit + Integration + Acceptance), deptrac, PHP-CS-Fixer, PHPStan level 8 (no baseline), Jenkins (dockerized, JCasC). API at `http://localhost:8083`, Jenkins at `http://localhost:8084`. Parts 1 (8080) and 2 (8081/8082) keep running untouched — containers, files, ports.

**Redis:** deliberately absent from this part's compose — nothing in scope uses it (no caching or rate limiting here), and an idle container is noise; it returns in parts 4–5.

**Out of scope (deliberate):** separate services, async messaging, deployment, real auth (part 2's lesson — a fake `X-Customer-Id` header keeps part 3 focused on the domain), real emails, reservation expiry/TTL, multi-warehouse, taxes/shipping fees, real payment.

**Conventions carried from parts 1–2:** DTO + Validator input handling; one JSON error shape `{"errors":[{"field","message"}]}` rendered by a single `kernel.exception` listener; route param `requirements` regexes on every path param; thin controllers; migrations only; entrypoint waits for MySQL and migrates the dev **and** test databases; all composer/console/codecept commands run inside the `php` container.

---

## 1. User stories

1. As a shopper (identified only by `X-Customer-Id`), I can browse the catalog and see live prices and availability.
2. As a shopper, I can build a cart, check out into a placed order (stock reserved atomically), pay through a gateway that sometimes declines or times out, and retry safely.
3. As a shopper, I can view my order history and order detail, and cancel — freely while placed, within a 24-hour refund window once paid; cancellation releases my reserved stock.
4. As an operator (same fake header, admin-ish endpoints), I can seed products, adjust stock, and mark paid orders shipped, which triggers a shipment notification and commits the stock out of the warehouse.
5. As the learner (Ian), I can watch a deptrac CI stage go red the moment any domain class imports framework code or one context reaches into another's internals.

## 2. Ubiquitous language & context map

### Glossary

| Term | Meaning (and the context that owns it) |
|---|---|
| **SKU** | Stock-keeping unit — the identity handshake between all contexts. Shared-kernel VO. |
| **Money** | An amount in **minor units** (int) plus an ISO currency code. Never a float, anywhere — including JSON. Shared-kernel VO. |
| **Product** (Catalog) | Merchandising truth: SKU, name, description, price, active flag. |
| **Stock item** (Inventory) | Warehouse truth for one SKU: quantity on hand, quantity reserved. |
| **Reservation** (Inventory) | Stock held against a placed order until it ships (committed) or the order is cancelled (released). |
| **Cart** (Ordering) | A customer's mutable pre-order: SKUs + quantities only — no prices (see §4). |
| **Line item / OrderLine** (Ordering) | Contractual truth at the moment of sale: SKU, name **snapshot**, unit-price **snapshot**, quantity. Immutable. |
| **Order** (Ordering) | The aggregate with the state machine `placed → paid → shipped`, cancellable per policy. |
| **Refund window** | 24 hours from `paidAt` during which a paid order may still be cancelled. |
| **Placement / Checkout** | Turning a cart into a placed order; reserves stock in the same transaction. |
| **Notification** (Notification) | An email that is logged, never sent. |

### The four contexts and why "product" is three different objects

| Context | Its model of "a thing we sell" | Knows | Deliberately does NOT know |
|---|---|---|---|
| **Catalog** | `Product` | SKU, name, description, price, active | stock levels, who ordered it |
| **Inventory** | `StockItem` | SKU, on-hand, reserved | price, name, description — a warehouse doesn't care what it costs |
| **Ordering** | `OrderLine` | SKU, name+price *as snapshotted at placement*, quantity | current catalog price — a later price change must never rewrite a past order |
| **Notification** | nothing — only event payloads | order id, customer, totals from events | any live product/stock/order state |

The only thing they agree on is the **SKU** (and Money/Quantity as value types) — that is the entire shared kernel. Same word, three models: this is the bounded-context lesson made concrete.

### Context map (relationships)

```
Catalog ──(customer/supplier: read-only ACL, ProductCatalog port)──▶ Ordering
Ordering ──(published language: Ordering\Domain\Event\*)──▶ Inventory (conformist)
Ordering ──(published language)──▶ Notification (conformist)
Inventory ──(published language: stock events)──▶ Catalog read model (product listing availability)
Shared kernel: Money, Sku, Quantity, Clock, DomainEvent plumbing (src/Shared/Domain)
```

- **Ordering → Catalog** is the only synchronous cross-context *call*: an anti-corruption port `ProductCatalog` (defined in Ordering's Domain, returning Ordering's own `ProductSnapshot` VO) whose adapter calls Catalog's published query API. Ordering never touches Catalog entities.
- Every other integration is **events only**, in-process and synchronous (§5).
- **Customers are external** to all four contexts — no customer table; `X-Customer-Id` is treated as an opaque `CustomerId` VO (an unbuilt fifth context).

## 3. Aggregates, invariants, value objects, repositories

Value objects shared by all contexts (`src/Shared/Domain`): **`Money`** (int minor units + currency; `add`/`multiply`; adding mismatched currencies throws; no float ever enters or leaves it), **`Sku`** (uppercase `[A-Z0-9-]{3,32}`, normalized, validated in constructor), **`Quantity`** (int 1–99), **`Clock`** interface (so the refund window is testable), **`DomainEvent`** interface + `RecordsEvents` trait (`recordThat()` / `releaseEvents()`), **`DomainEventDispatcher`** port.

All aggregate mutation happens through aggregate methods — no public setters anywhere in any Domain layer; nothing outside an aggregate mutates it. Repositories are **interfaces in Domain**, Doctrine adapters in Infrastructure. Identity is created **in the domain** (UUIDv7 VOs — `OrderId`, `ReservationId`), not by DB auto-increment, so an aggregate can put its own id into events before any flush.

Invariants hold under **concurrent requests**, not just per-process: the guards are check-then-act in PHP, so every mutation path loads its aggregate through a locking repository read (`StockItemRepository::bySkuForUpdate`, `OrderRepository::byIdForUpdate`, `CartRepository::byCustomerForUpdate` at checkout — `SELECT … FOR UPDATE` inside the command's transaction, re-reading current state). Payment re-asserts payability on a locked re-read AFTER the external charge, since the world may change while the gateway call is in flight. A DB `CHECK (0 ≤ reserved ≤ on_hand)` backstops S1 at the schema level, the same belt-and-braces as R1's unique index.

### Catalog

**`Product` (aggregate root)** — id: `Sku`. VOs: `Sku`, `Money`, `ProductName`. Invariants as rules:
- P1: price is `Money` with amount > 0.
- P2: SKU is immutable after creation and unique (unique index; violation surfaced as 422, not a racy pre-check — part 1's lesson).
- P3: an inactive product cannot be added to carts (enforced at the Ordering side via the ACL, which only serves active products).

Repository: `ProductRepository` (`get(Sku): Product`, `add`, `bySkuOrNull`). Events emitted: `ProductAdded(sku, name, priceMinor, currency)`, `ProductRenamed(sku, name)`, `ProductPriceChanged(sku, priceMinor, currency)`, `ProductDeactivated(sku)`, `ProductReactivated(sku)` — consumed only by the product-list projector (§7). Every merchandising mutation publishes, or the projection's row would drift from the write model permanently.

**Published query API** (`Catalog\Application\PublicApi\ProductCatalogQuery`): the one class other contexts' *adapters* may call. Returns plain DTOs, never entities.

### Inventory

**`StockItem` (aggregate root)** — id: `Sku`. Fields: `onHand`, `reserved` (ints ≥ 0). Invariants:
- S1: `0 ≤ reserved ≤ onHand` at all times.
- S2: `reserve(qty)` throws `InsufficientStock` if `available() = onHand − reserved < qty`.
- S3: `release(qty)` cannot release more than is reserved; `commit(qty)` (shipment) decrements both `onHand` and `reserved`.
- S4: setting `onHand` below `reserved` is rejected (422).

**`Reservation` (aggregate root)** — id: `ReservationId`; fields: `orderId` (unique), lines `[{sku, qty}]`, status `active | released | committed`. Invariants:
- R1: exactly one reservation per order.
- R2: a reservation is released or committed **at most once** (status guard — the seed of part 4's idempotency lesson, called out in the study guide).

Repositories: `StockItemRepository`, `ReservationRepository` (`byOrderId`). Events: `StockReceived(sku, qty, available)`, `StockReserved(sku, orderId, qty, available)`, `StockReleased(sku, orderId, qty, available)`, `StockCommitted(sku, orderId, qty, available)` — payloads carry `available` so projectors need no lookback.

### Ordering

**`Cart` (aggregate root)** — id: `CustomerId` (one cart per customer). Lines: `sku → Quantity` only. Invariants:
- C1: quantities are `Quantity` (1–99); setting a line replaces it; max 50 distinct lines.
- C2: **carts store no prices.** Prices and names are resolved through the `ProductCatalog` port at read time and snapshotted only at checkout — a cart held for a week checks out at *today's* price; the *order* then freezes it. (The alternative — price-at-add-time — silently sells at stale prices; state the trade-off in the study guide.)
- C3: all cart lines must share one currency (mismatch rejected at add: 422 `"Cart currency mismatch."` — exercises Money's same-currency rule).

Cart emits no events — there are no consumers, and an event nobody listens to is ceremony (said explicitly; not every aggregate publishes).

**`Order` (aggregate root)** — id: `OrderId` (UUIDv7). Children: `OrderLine` entities (reachable only through the root). VOs: `Money`, `Sku`, `Quantity`, `CustomerId`, `OrderStatus` (pure PHP backed enum — enums are not framework code). Invariants as rules:
- O1: **the only legal transitions are** `placed → paid`, `placed → cancelled`, `paid → shipped`, `paid → cancelled` **iff** `now < paidAt + 24h` (refund window, checked against the injected `Clock`). `shipped` and `cancelled` are terminal. Any other transition throws `IllegalOrderTransition`.
- O2: `total` always equals the sum of `line.unitPrice × line.quantity`; enforced by construction — total is computed, never stored independently, and lines are immutable after placement.
- O3: an order has ≥ 1 line; all lines share the order's currency.
- O4: nothing outside the aggregate mutates it: state changes only via `place()` (named constructor), `pay(TransactionId)`, `ship()`, `cancel(Clock)`.
- O5: `pay()` is idempotent-hostile by design: paying a paid order throws (409) — retry safety lives at the gateway seam (§6), not by making `pay()` a no-op.

Repository: `OrderRepository` (`get(OrderId)`, `add`; history reads go to the read model, not the repository). Events (past tense, with payloads):

| Event | Payload |
|---|---|
| `OrderPlaced` | orderId, customerId, lines `[{sku, name, quantity, unitPriceMinor, currency}]`, totalMinor, currency, placedAt |
| `OrderPaid` | orderId, customerId, transactionId, totalMinor, currency, paidAt |
| `OrderShipped` | orderId, customerId, shippedAt |
| `OrderCancelled` | orderId, customerId, hadBeenPaid (bool), cancelledAt |

### Notification

Deliberately thin — a context with almost no domain, shown as such honestly. Domain: `EmailMessage` VO (recipient customerId, subject, body) and a `Mailer` port. Application: three event subscribers (§5) that compose an `EmailMessage` and hand it to the port. Infrastructure: `LoggingMailer` writes a `notification_log` row **and** a PSR-3 log line — logged, never sent. No aggregate; the log row is an append-only record, not a lifecycle.

## 4. Data model (per-context tables, no cross-context FKs)

All contexts share one MySQL schema (`app` / `app_test`) but tables are context-prefixed and there are **zero foreign keys across context boundaries** — SKUs and order ids cross boundaries as plain values. This is the part-4 fault line drawn in advance: the schema could be split by prefix tomorrow.

| Table | Context | Columns (abridged) |
|---|---|---|
| `catalog_product` | Catalog | sku PK, name, description NULL, price_minor INT UNSIGNED, currency CHAR(3), active BOOL, timestamps |
| `inventory_stock_item` | Inventory | sku PK, on_hand INT UNSIGNED, reserved INT UNSIGNED, timestamps |
| `inventory_reservation` | Inventory | id CHAR(36) PK, order_id CHAR(36) **UNIQUE**, status VARCHAR(10), timestamps |
| `inventory_reservation_line` | Inventory | reservation_id FK, sku, quantity |
| `ordering_cart` | Ordering | customer_id PK (VARCHAR 64), currency CHAR(3) NULL, timestamps |
| `ordering_cart_line` | Ordering | cart_id FK, sku, quantity; UNIQUE (cart_id, sku) |
| `ordering_order` | Ordering | id CHAR(36) PK, customer_id, status VARCHAR(10), currency CHAR(3), placed_at, paid_at NULL, shipped_at NULL, cancelled_at NULL, transaction_id NULL; idx (customer_id, placed_at) |
| `ordering_order_line` | Ordering | order_id FK, sku, name, unit_price_minor, quantity |
| `ordering_payment_attempt` | Ordering (infra) | order_id, attempt_no, token, outcome, created_at — owned by the fake gateway adapter (§6) |
| `notification_log` | Notification | id, type, customer_id, order_id, subject, body, created_at |
| `read_product_list` | read model (§7) | sku PK, name, price_minor, currency, active, available INT |
| `read_order_summary` | read model (§7) | order_id PK, customer_id, status, total_minor, currency, line_count, placed_at, updated_at; idx (customer_id, placed_at) |

`Money` and `Sku` map as Doctrine **embeddables/custom types via XML mappings that live in Infrastructure** — entities stay annotation-free (§6). Enum-like columns: VARCHAR + PHP backed enum, as in part 2.

## 5. The event seam (in-process, synchronous)

**Recording:** aggregates `recordThat(...)` events internally. **Dispatch:** each Application command handler, after persisting, pulls `releaseEvents()` and hands them to the `DomainEventDispatcher` **port** (Shared Domain). The adapter `SymfonyDomainEventDispatcher` (Shared Infrastructure) wraps Symfony's EventDispatcher; consuming subscribers are Application-layer classes autoconfigured onto it. The domain and application layers never see Symfony.

**Everything runs inside the command's DB transaction.** That is what makes reserve-or-rollback possible: if Inventory's subscriber throws `InsufficientStock`, order placement rolls back atomically. Stated in the study guide as exactly the guarantee part 4 takes away (then rebuilds with outbox + compensation).

| Event | Consumer (context / subscriber) | Effect |
|---|---|---|
| `OrderPlaced` | Inventory `ReserveStockOnOrderPlaced` | creates `Reservation`, `reserve()`s each `StockItem`; `InsufficientStock` → whole checkout rolls back → 409 |
| `OrderPlaced` | Notification `SendOrderConfirmation` | logs confirmation email |
| `OrderPlaced` | Ordering `OrderSummaryProjector` | inserts `read_order_summary` row |
| `OrderPaid` | Notification `SendPaymentReceipt` | logs receipt email |
| `OrderPaid` / `OrderShipped` / `OrderCancelled` | Ordering `OrderSummaryProjector` | updates summary status |
| `OrderShipped` | Notification `SendShipmentNotice` | logs shipment email |
| `OrderShipped` | Inventory `CommitStockOnOrderShipped` | commits reservation: `onHand −= qty`, `reserved −= qty` (stock leaves the building — this keeps S1 honest end-to-end) |
| `OrderCancelled` | Inventory `ReleaseStockOnOrderCancelled` | releases reservation (R2 guards double-release) |
| Catalog product events + Inventory stock events | Catalog `ProductListProjector` | maintains `read_product_list` (§7) |

## 6. Hexagonal layout and the deptrac law

### Directory structure

```
order-system/
├── compose.yaml  docker/  jenkins/  deptrac.yaml  .env  .gitignore  README.md
├── config/            # Symfony config incl. per-context Doctrine XML mapping dirs
├── public/  bin/  migrations/
├── src/
│   ├── Kernel.php     # excluded from deptrac analysis (the one framework touchpoint at root)
│   ├── Shared/
│   │   ├── Domain/            # Money, Sku, Quantity, CustomerId, Clock, DomainEvent,
│   │   │                      #   RecordsEvents, DomainEventDispatcher (port)
│   │   └── Infrastructure/    # SymfonyDomainEventDispatcher, SystemClock,
│   │                          #   Doctrine custom types, kernel.exception listener
│   ├── Catalog/
│   │   ├── Domain/            # Product, ProductRepository, Event/, exceptions
│   │   ├── Application/       # command+query handlers; PublicApi/ProductCatalogQuery;
│   │   │                      #   Projection/ProductListProjector
│   │   └── Infrastructure/    # Http/ (controllers, DTOs), Persistence/ (Doctrine repos)
│   ├── Ordering/
│   │   ├── Domain/
│   │   │   ├── Cart.php  Order.php  OrderLine.php  OrderStatus.php  OrderId.php ...
│   │   │   ├── Event/         # OrderPlaced, OrderPaid, OrderShipped, OrderCancelled
│   │   │   ├── Port/          # PaymentGateway, ProductCatalog (ACL) + ProductSnapshot VO
│   │   │   └── CartRepository.php  OrderRepository.php  exceptions
│   │   ├── Application/       # AddCartLine, RemoveCartLine, PlaceOrder, PayOrder,
│   │   │                      #   ShipOrder, CancelOrder handlers; Projection/OrderSummaryProjector
│   │   └── Infrastructure/    # Http/, Persistence/, Payment/FakePaymentGateway,
│   │                          #   Acl/CatalogProductCatalog (adapter → Catalog PublicApi)
│   ├── Inventory/   Domain/ | Application/ (subscribers, SetStock/ReceiveStock) | Infrastructure/
│   └── Notification/ Domain/ (EmailMessage, Mailer port) | Application/ | Infrastructure/
└── tests/  Unit/  Integration/  Acceptance/
```

### Dependency rules → `deptrac.yaml` (this becomes CI law)

Layers are directory-collected per context (`{Ctx}Domain` excludes its `Event/` subdir, which forms `{Ctx}Events`; `CatalogPublicApi` = `Catalog/Application/PublicApi`); a `Vendor` layer is regex-collected (`^(Symfony|Doctrine|Psr|Twig)\\`). **Any dependency not explicitly allowed fails the build.**

| Layer | May depend on | Notes |
|---|---|---|
| `SharedDomain` | — | zero deps, zero framework |
| `{Ctx}Events` | `SharedDomain` | the published language: plain DTO-ish classes |
| `{Ctx}Domain` | `SharedDomain`, own `{Ctx}Events` | **no `Vendor` — the spec's done-when, mechanically enforced** |
| `{Ctx}Application` | own Domain + Events, `SharedDomain`, **other contexts' `{Ctx}Events` only** (Inventory/Notification/Ordering-projector consume `OrderingEvents`; Catalog projector consumes `InventoryEvents`) | no `Vendor` either — handlers are plain PHP; controllers call them directly (no bus library) |
| `{Ctx}Infrastructure` | own Application/Domain/Events, `SharedDomain`, `SharedInfrastructure`, `Vendor`; OrderingInfrastructure additionally `CatalogPublicApi` (the ACL adapter's one legal cross-context call) | |
| `SharedInfrastructure` | `SharedDomain`, all `{Ctx}Events` (dispatcher), `Vendor` | |

What this forbids, explicitly: any Domain→Vendor import (a single `use Doctrine\...` in an entity goes red — hence XML mappings); Ordering touching `Catalog\Domain` or Inventory touching `Ordering\Application`; any context writing to another's tables (convention, backed by the layer rules on repository classes). Swapping `FakePaymentGateway` for a real adapter touches only `Ordering/Infrastructure` — the spec's hexagonal claim, demonstrable.

### Faked payment gateway

Port (Ordering Domain):

```php
interface PaymentGateway
{
    /** @throws PaymentGatewayTimedOut @throws PaymentGatewayUnavailable */
    public function charge(OrderId $orderId, Money $amount, PaymentMethodToken $token): PaymentResult;
}
// PaymentResult::approved(transactionId) | PaymentResult::declined(reason)
```

`FakePaymentGateway` (Ordering Infrastructure) selects behavior by **token** — test-controllable over plain HTTP, no backdoors:

| Token | Behavior |
|---|---|
| `tok_success` | approved, transactionId `fake_<uuid>` |
| `tok_declined` | declined `"insufficient_funds"` |
| `tok_timeout_once` | **first** attempt per order throws `PaymentGatewayTimedOut`; subsequent attempts approve (attempt count persisted in `ordering_payment_attempt`, so it works across separate HTTP requests in acceptance tests) |
| `tok_error` | always throws `PaymentGatewayUnavailable` |
| anything else | 422 validation error |

**Decision: payment failure leaves the order `placed`-and-payable — placement never fails because of payment.** Justification: the spec's state machine has `placed → paid` as a *transition*, so `placed` must be a real, durable state; real gateways fail transiently and retry must be cheap (re-running checkout would need stock release + re-reserve churn); and it gives acceptance tests an honest retry story. Mapping: declined → 422 `"Payment was declined."`; timeout → 502 `"Payment gateway timed out — the charge did not complete; safe to retry."`; gateway error → 502. In all three cases the order stays `placed`, stock stays reserved (no reservation expiry — named as the real-world follow-up, out of scope).

## 7. CQRS-lite read models

Two read models, both **projected synchronously in the same transaction** as the write (fine in-process; part 4 makes them eventually consistent):

| Read model | Serves | Populated by |
|---|---|---|
| `read_product_list` | `GET /api/products` — listing with price **and** availability | Catalog product events + Inventory stock events (`available` from payloads). Earns its keep: it composes **two contexts** without a cross-boundary join at request time — the query stays legal under the deptrac law. |
| `read_order_summary` | `GET /api/orders` — customer order history | `OrderSummaryProjector` on the four order events. Earns its keep: history is a flat status/total listing that shouldn't hydrate full aggregates. |

**Honest ceremony note (in the study guide):** in a monolith on one schema, both could be SQL joins; product *detail* and order *detail* deliberately read the write model to prove you don't project everything. The projection mechanics — not the performance — are the lesson here; CQRS pays rent only once read and write shapes truly diverge or move to different stores (part 4).

## 8. HTTP API contract

Base `http://localhost:8083`. JSON in/out; parts 1–2 error shape for 400/401/404/405/409/422/502/500. **Auth: none** — customer endpoints require `X-Customer-Id` (1–64 chars; missing/blank → 401 in the error shape); auth was part 2's lesson, repeating it here would dilute the domain focus. Admin-ish endpoints (marked ⚙) require no header at all — they exist for seeding/ops and tests. Money renders as `{"amountMinor": 1999, "currency": "EUR"}` — no float ever crosses the wire.

Route requirements: `sku` → `[A-Z0-9-]{3,32}`, `id` (orders) → UUID regex.

**Catalog & stock**

| Method & path | Success | Errors |
|---|---|---|
| ⚙ `POST /api/products` `{sku, name, description?, priceMinor, currency}` | 201 Product | 422 (dup SKU, amount ≤ 0, bad currency), 400 |
| `GET /api/products` (`page`≥1, `limit` 1–100, defaults 1/20) | 200 `{products:[{sku,name,price,active,available}],page,limit,total,pages}` — from `read_product_list` | 422 bad params |
| `GET /api/products/{sku}` | 200 Product detail (write model) | 404 |
| ⚙ `PATCH /api/products/{sku}` `{name?, priceMinor?+currency?, active?}` | 200 Product | 404, 422 |
| ⚙ `PUT /api/stock/{sku}` `{onHand}` | 200 `{sku,onHand,reserved,available}` | 422 (`onHand < reserved`), 400 |
| `GET /api/stock/{sku}` | 200 stock view | 404 (no stock record) |

**Cart** (customer header required)

| Method & path | Success | Errors |
|---|---|---|
| `GET /api/cart` | 200 `{lines:[{sku,name,unitPrice,quantity,lineTotal}], total}` — prices resolved live via the ACL | 401 |
| `PUT /api/cart/lines/{sku}` `{quantity}` | 200 cart | 401, 422 (qty out of 1–99, unknown/inactive product `"Unknown or inactive product."`, currency mismatch) |
| `DELETE /api/cart/lines/{sku}` | 204 | 401, 404 (line not in cart) |

**Orders** (customer header required except ⚙; another customer's order id → **404**, part 2's existence-hiding policy)

| Method & path | Success | Errors |
|---|---|---|
| `POST /api/orders` (checkout — no body; consumes the cart) | 201 Order (`placed`; cart emptied; stock reserved) | 401, 422 (empty cart), **409** `"Insufficient stock for <sku>."` |
| `GET /api/orders` | 200 `{orders:[summary…]}` — from `read_order_summary`, newest first | 401 |
| `GET /api/orders/{id}` | 200 Order detail (lines, status, timestamps, total) | 401, 404 |
| `POST /api/orders/{id}/payment` `{paymentMethodToken}` | 200 Order (`paid`) | 401, 404, 409 (not `placed`), 422 (declined / bad token), 502 (timeout / gateway error — order stays payable) |
| `POST /api/orders/{id}/cancel` | 200 Order (`cancelled`) | 401, 404, 409 (`shipped`/`cancelled`, or paid past window: `"Refund window has closed."`) |
| ⚙ `POST /api/orders/{id}/ship` | 200 Order (`shipped`) | 404, 409 (not `paid`) |
| ⚙ `GET /api/notifications?orderId=` | 200 `{notifications:[{type,customerId,orderId,subject,createdAt}]}` | 422 |

409 vs 422 rule, pinned: 422 = the request itself is invalid; **409 = the request is fine but conflicts with current state** (stock, state machine, refund window).

## 9. Test strategy (Codeception, all inside the `php` container)

**Unit** (`tests/Unit`) — pure domain, no container, no DB; the pyramid's base and where DDD pays off:
- `Money` (arithmetic, currency-mismatch throws, no-float construction), `Sku`, `Quantity` validation tables.
- **`Order` state machine: the full transition table** — every legal transition, every illegal one throws; refund window with a fixed `Clock` (inside/one-second-past-edge/outside); O2 totals; event recording (place → `OrderPlaced` with exact payload).
- `Cart` rules (C1–C3), `StockItem` (S1–S4 incl. reserve/release/commit edges), `Reservation` once-only guard (R2).

**Integration** (`tests/Integration`, Symfony module, real MySQL `app_test`):
- Doctrine adapters round-trip every aggregate (embeddable/XML mapping correctness — the likeliest breakage of the "pure entities" move).
- **Event wiring:** `PlaceOrder` handler → stock actually reserved + notification row + summary row, all in one transaction; `InsufficientStock` → everything rolled back.
- `FakePaymentGateway` per-token behavior incl. persisted `tok_timeout_once` attempt counting; projectors update both read models.

**Acceptance** (`tests/Acceptance`, HTTP against nginx, what the spec's done-when demands):
1. **Full happy lifecycle:** seed product+stock → cart → checkout 201 → `GET /api/stock/{sku}` shows reserved → pay 200 → ship 200 → stock committed → notifications log shows confirmation + receipt + shipment.
2. **Payment failure:** declined 422 → order still `placed` → `tok_timeout_once` 502 → retry 200 `paid`. Gateway-error 502 path.
3. **Cancellation with stock release:** cancel a placed order → 200, reserved back to 0; cancel a paid order (within window) → released; cancel after ship → 409 and stock stays committed.
4. Insufficient stock 409 rolls back (no order, no reservation, cart intact); cross-customer order access → 404; cart validation table; error shape on 400/404/405.

Refund-window *expiry* is unit-tested via `Clock` (acceptance tests can't time-travel; said honestly in the study guide).

## 10. Docker Compose & repo gotcha

`compose.yaml`, project `order-system`, mirroring part 2: `php` (8.3-fpm + pdo_mysql, intl, opcache; composer; entrypoint waits for MySQL, installs if `vendor/` missing, migrates `app` **and** `app_test`), `nginx` (**8083:80**), `mysql:8` (unpublished, named volume, healthcheck, init script creates `app_test`). No Redis (see header note).

**Repo gotcha (must-do, verified):** Ian's global gitignore has an unanchored `.env` rule. `order-system/.gitignore` **must** contain `!/.env` and `!/.env.*`, verified with `git check-ignore` against every committed-intent env file.

## 11. Jenkins — CI with an architecture gate

Part-2 approach copied wholesale (`jenkins/` compose, JCasC `casc.yaml`, pinned `plugins.txt`, Job DSL seed, repo bind-mounted read-only, stages run in the app's own php image via docker-workflow, working-tree rsync), adjusted: project name **`order-system-jenkins`**, port **8084:8080**, job **`order-system`**, CI compose project `order-system-ci` with no published ports.

Pipeline stages (each red-fails):
1. `composer validate --strict` + `composer install`
2. `php-cs-fixer fix --dry-run --diff`
3. `phpstan analyse` (level 8, no baseline, `src/` + `tests/`)
4. **`deptrac analyse` — the architecture gate.** This stage *is* the spec's done-when: one framework import in a Domain layer, one context reaching into another's internals, and the build is red. Demonstrated deliberately (add `use Doctrine\ORM\...` to `Order.php` on a branch → red → revert → green).
5. Codeception: Unit + Integration + Acceptance against the isolated CI compose stack, `down -v` in `post { always }`.

## 12. Milestones

**Stage 1 — skeleton, shared kernel, architecture law, Catalog + Inventory basics**
1. Scaffold + compose (php/nginx/mysql), entrypoint, `.gitignore` negations verified via `git check-ignore`. Done: default page on 8083; parts 1–2 untouched and healthy.
2. Shared kernel (`Money`, `Sku`, `Quantity`, `Clock`, event plumbing + Symfony dispatcher adapter) with unit tests.
3. `deptrac.yaml` encoding §6 fully, running locally — **wired before there is much code to constrain**, so every later line is born under the law.
4. Catalog: `Product` aggregate, XML-mapped Doctrine repo, product endpoints, PublicApi query. Inventory: `StockItem`, stock endpoints. Migrations on `app` + `app_test`. Unit + first integration tests green.

**Stage 2 — Ordering: the heart**
5. `Cart` + ACL port/adapter + cart endpoints. `Order` aggregate + state machine + full unit transition table.
6. Checkout: `PlaceOrder` handler, `OrderPlaced` → Inventory reservation (rollback on `InsufficientStock`), `Reservation` aggregate.
7. Payment: gateway port, fake with all four token behaviors, `PayOrder`; ship + cancel handlers with refund window; `OrderCancelled` → release, `OrderShipped` → commit.
8. Acceptance lifecycle tests (§9 items 1–4) green. Done: the spec's done-when flows all pass over HTTP.

**Stage 3 — Notification, read models, CI, polish**
9. Notification context (Mailer port, LoggingMailer, three subscribers, `GET /api/notifications`); acceptance assertions on the log.
10. Read models + projectors (`read_product_list`, `read_order_summary`), listings switched onto them; ceremony note written.
11. Jenkins on 8084: fresh `docker compose up` → one click → green; deliberate deptrac red demonstrated and reverted.
12. README + study guide (bounded-context table, event seam diagram, payment decision, CQRS honesty, part-4 foreshadowing); final pass against the spec's done-when.
