# Part 3 study guide — reading the order system

A guided reading path through the code, in the order the ideas build on each
other. Parts 1–2 taught you the mechanics: kernel, routing, thin
controllers, DTO + Validator, the one error shape, tokens and voters, Redis,
CI. All of that is either here unchanged or deliberately absent (auth is a
fake `X-Customer-Id` header on purpose — that lesson is done). What's new in
part 3 is not mechanics at all: it is *where code is allowed to live and
what it is allowed to know*. Four bounded contexts, aggregates that enforce
their own rules, a domain layer with zero framework imports, and a deptrac
file that turns all of those sentences into a failing build.

Read with the stack running (`docker compose up -d --wait`, API on
`http://localhost:8083`) so you can poke every claim with curl. Each section
names real files. When a claim sounds strong ("the domain imports no
framework code, ever"), the habit from parts 1–2 still applies: find the
test — or here, often the deptrac rule — that proves it.

---

## 1. The context map on the ground

Before reading any single class, walk the directory tree. The architecture
is visible from `ls`:

```
src/
├── Kernel.php            # the ONE framework touchpoint at the root (deptrac excludes it)
├── Shared/
│   ├── Domain/           # Money, Sku, Quantity, CustomerId, Clock, UuidV7,
│   │                     #   DomainEvent, RecordsEvents, DomainEventDispatcher,
│   │                     #   TransactionBoundary, StateConflict
│   └── Infrastructure/   # SymfonyDomainEventDispatcher, SystemClock,
│                         #   DoctrineTransactionBoundary, Doctrine custom types,
│                         #   JsonExceptionListener, ApiProblem, JsonBody
├── Catalog/
│   ├── Domain/           # Product, ProductName, ProductRepository, Event/, exceptions
│   ├── Application/      # command handlers, PublicApi/, Projection/
│   └── Infrastructure/   # Http/ (controller, DTOs), Persistence/
├── Ordering/
│   ├── Domain/           # Cart, Order, OrderLine, OrderStatus, OrderId,
│   │                     #   Event/, Port/ (PaymentGateway, ProductCatalog),
│   │                     #   repositories, exceptions
│   ├── Application/      # PutCartLine..., PlaceOrder, PayOrder, ShipOrder,
│   │                     #   CancelOrder handlers; Query/; Projection/
│   └── Infrastructure/   # Http/, Persistence/, Payment/FakePaymentGateway,
│                         #   Acl/CatalogProductCatalog
├── Inventory/            # Domain/ | Application/ (subscribers, SetStockLevel) | Infrastructure/
└── Notification/         # Domain/ (EmailMessage, Mailer port) | Application/ | Infrastructure/
```

Every context repeats the same three rings — Domain, Application,
Infrastructure — and the rings mean the same thing everywhere:

- **Domain**: the business rules. Pure PHP. No Symfony, no Doctrine, no
  PSR interfaces — nothing but `App\Shared\Domain`, the context's own
  classes, and the PHP language itself (`\DateTimeImmutable` and backed
  enums are language, not framework).
- **Application**: use cases. Command handlers, event subscribers,
  projectors. Also plain PHP — no framework attributes, no bus library;
  controllers call handlers directly, and subscriber wiring lives in
  [`config/services.yaml`](../config/services.yaml), not in attributes.
- **Infrastructure**: everything that touches the outside world — HTTP
  controllers, Doctrine repositories, the fake payment gateway, the logging
  mailer. This is the only ring allowed to import vendor code.

The fastest way to *feel* this is to open two files and compare their `use`
blocks. [`src/Ordering/Domain/Order.php`](../src/Ordering/Domain/Order.php)
imports its own events plus four `App\Shared\Domain` classes — nothing
else. [`src/Ordering/Infrastructure/Http/OrderController.php`](../src/Ordering/Infrastructure/Http/OrderController.php)
imports half of `Symfony\Component\HttpFoundation`. Same context,
different ring, different world.

What each context's Domain pointedly does **not** see:

| Domain | Sees | Does NOT see — and deptrac makes this law |
|---|---|---|
| `Catalog/Domain` | SharedDomain, own `Event/` | any other context; Doctrine (mappings are XML in [`config/doctrine/Catalog/`](../config/doctrine/Catalog/Product.orm.xml)) |
| `Ordering/Domain` | SharedDomain, own `Event/` | `Catalog\Domain\Product` — it defines its *own* `Port/ProductCatalog` instead |
| `Inventory/Domain` | SharedDomain, own `Event/` | `Ordering\Domain\OrderId` — order ids cross the boundary as plain strings (see [`Reservation.php`](../src/Inventory/Domain/Reservation.php)) |
| `Notification/Domain` | SharedDomain only | everything: it has no repository, no aggregate, just `EmailMessage` and the `Mailer` port |

The enforcement is [`deptrac.yaml`](../deptrac.yaml) — read it now, top to
bottom; it is short and it is the part's spine. Three things to notice:

1. It is an **allowlist**. `SharedDomain: ~` means the shared kernel
   depends on nothing. Any dependency not listed is a violation.
2. Each context's `Event/` subdirectory is split out as its own layer
   (`OrderingEvents` etc.). That is what lets Inventory's *Application*
   consume Ordering's *events* without being allowed anywhere near
   Ordering's *domain*: `InventoryApplication: [..., OrderingEvents]`.
3. One asymmetric edge: `OrderingInfrastructure` may see
   `CatalogPublicApi`. That single line is the only legal synchronous
   cross-context call in the system (§3.2).

Run it yourself: `docker compose exec php vendor/bin/deptrac` — green now,
and CI stage 5 ([`Jenkinsfile`](../Jenkinsfile)) fails red if it ever isn't.

---

## 2. Life of a checkout

The single most instructive request in the system is `POST /api/orders`.
Three contexts participate in one database transaction. Follow it file by
file; at every hop, note which ring you are standing in.

Setup, if you want to drive it live:

```sh
curl -s -X POST localhost:8083/api/products -H 'Content-Type: application/json' \
  -d '{"sku":"WIDGET-1","name":"Widget","priceMinor":1999,"currency":"EUR"}'
curl -s -X PUT localhost:8083/api/stock/WIDGET-1 -H 'Content-Type: application/json' \
  -d '{"onHand":10}'
curl -s -X PUT localhost:8083/api/cart/lines/WIDGET-1 -H 'X-Customer-Id: cust-1' \
  -H 'Content-Type: application/json' -d '{"quantity":2}'
curl -s -X POST localhost:8083/api/orders -H 'X-Customer-Id: cust-1'
```

### 2.1 The edge (Ordering Infrastructure)

nginx → `public/index.php` → routing, exactly as in parts 1–2. The route
lands on
[`OrderController::place()`](../src/Ordering/Infrastructure/Http/OrderController.php).
First line:
[`CustomerContext::requireCustomer()`](../src/Ordering/Infrastructure/Http/CustomerContext.php)
— the whole of part 3's "auth", an opaque header validated into a
`CustomerId` VO, 401 in the standard error shape if missing. Then the
controller does what thin controllers do: build a `PlaceOrder` command
(a dumb DTO in [`Application/Command/PlaceOrder.php`](../src/Ordering/Application/Command/PlaceOrder.php))
and hand it to the handler. **Boundary crossed**: HTTP stops existing here.
Nothing below this file knows what a Request is.

### 2.2 The use case (Ordering Application)

[`PlaceOrderHandler::handle()`](../src/Ordering/Application/Command/PlaceOrderHandler.php)
is the richest twenty lines in the project. Read it slowly. Every
collaborator in its constructor is a **port** — an interface defined in a
Domain layer, satisfied by an Infrastructure adapter chosen in
[`services.yaml`](../config/services.yaml):

| Port (interface, Domain) | Adapter (Infrastructure) |
|---|---|
| `Ordering\Domain\CartRepository` | `Persistence\DbalCartRepository` |
| `Ordering\Domain\OrderRepository` | `Persistence\DoctrineOrderRepository` |
| `Ordering\Domain\Port\ProductCatalog` | `Acl\CatalogProductCatalog` |
| `Shared\Domain\DomainEventDispatcher` | `Shared\Infrastructure\SymfonyDomainEventDispatcher` |
| `Shared\Domain\TransactionBoundary` | `Shared\Infrastructure\Doctrine\DoctrineTransactionBoundary` |
| `Shared\Domain\Clock` | `Shared\Infrastructure\SystemClock` |

The handler's body, inside `transactional(...)`:

1. **Locked cart read** — `byCustomerForUpdate()` issues
   `SELECT ... FOR UPDATE` ([`DbalCartRepository`](../src/Ordering/Infrastructure/Persistence/DbalCartRepository.php)),
   so two devices racing one checkout serialize on the cart row; the loser
   re-reads a consumed cart and gets `EmptyCart` → 422. Proven over real
   HTTP in [`ConcurrentCheckoutCest::theSameCartCheckedOutTwiceConcurrentlyPlacesOneOrder`](../tests/Acceptance/ConcurrentCheckoutCest.php).
2. **Live pricing through the ACL** — for each cart line, the handler asks
   `ProductCatalog::activeProduct($sku)`. The cart stored no prices (C2);
   this is the moment today's price gets fetched and frozen. A product gone
   inactive since it was added → `ProductNoLongerAvailable` → 409.
3. **The aggregate is born** —
   [`Order::place()`](../src/Ordering/Domain/Order.php). **Boundary
   crossed**: pure domain now. The order mints its own `OrderId` (UUIDv7,
   in the domain — not DB auto-increment) precisely so the next step is
   possible before any flush, validates O3 (≥1 line, one currency), and
   `recordThat(new OrderPlaced(...))` — recorded, not dispatched. Look at
   [`RecordsEvents`](../src/Shared/Domain/RecordsEvents.php): the aggregate
   buffers facts; it does not know a dispatcher exists.
4. **Persist and consume** — `orders->add($order)` (Doctrine cascades the
   lines), `cart->clear()`, `carts->save()`.
5. **Dispatch** — `$this->events->dispatch(...$order->releaseEvents())`.
   The handler hands the buffered `OrderPlaced` to the
   `DomainEventDispatcher` **port**; the
   [`SymfonyDomainEventDispatcher`](../src/Shared/Infrastructure/SymfonyDomainEventDispatcher.php)
   adapter (Infrastructure — the first framework code since the
   controller) forwards it to Symfony's in-process EventDispatcher,
   synchronously, same call stack, same transaction.

### 2.3 Inventory answers (Inventory Application → Domain)

Symfony routes the event to
[`ReserveStockOnOrderPlaced`](../src/Inventory/Application/Subscriber/ReserveStockOnOrderPlaced.php)
— wired in [`services.yaml`](../config/services.yaml) under
`kernel.event_listener`, because Application classes may not carry framework
attributes. Note what the subscriber receives: `OrderPlaced`'s payload is
**scalars** — `string $orderId`, `array $lines` — not Ordering's VOs
([`OrderPlaced.php`](../src/Ordering/Domain/Event/OrderPlaced.php) explains
why). Inventory rebuilds its *own* `Sku` and `Quantity` from them: a
conformist consumer of a published language.

For each line: `bySkuForUpdate()` (lock the stock row — two checkouts
racing the last unit serialize here), then
[`StockItem::reserve()`](../src/Inventory/Domain/StockItem.php), which
throws `InsufficientStock` if `available() < qty`. Then one
[`Reservation`](../src/Inventory/Domain/Reservation.php) for the whole
order (R1: unique index on `order_id`).

**The payoff of "same transaction"**: if `InsufficientStock` flies, it
erupts up through the dispatcher, through the handler, and the
[`DoctrineTransactionBoundary`](../src/Shared/Infrastructure/Doctrine/DoctrineTransactionBoundary.php)
rolls back *everything* — the order row, the emptied cart, any
already-reserved earlier lines. The customer keeps the cart and gets a 409.
[`CheckoutFlowCest::insufficientStockOnALaterLineRollsBackEarlierReservations`](../tests/Integration/Ordering/CheckoutFlowCest.php)
pins the nastiest variant.

How does that 409 happen when
[`OrderController`](../src/Ordering/Infrastructure/Http/OrderController.php)
never catches `InsufficientStock`? It *can't* catch it — importing
`Inventory\Domain` in an Ordering controller is a deptrac violation.
Instead `InsufficientStock` implements the shared marker
[`StateConflict`](../src/Shared/Domain/StateConflict.php), and the one
[`JsonExceptionListener`](../src/Shared/Infrastructure/Http/JsonExceptionListener.php)
maps any `StateConflict` to 409 in the parts-1–2 error shape without
knowing which context threw it. The dependency arrows stay legal; the
HTTP contract stays uniform.

### 2.4 Notification logs, projectors project (still the same transaction)

Two more subscribers of the same event:

- [`SendOrderConfirmation`](../src/Notification/Application/Subscriber/SendOrderConfirmation.php)
  composes an [`EmailMessage`](../src/Notification/Domain/EmailMessage.php)
  purely from the event's scalars and hands it to the `Mailer` **port**;
  the [`LoggingMailer`](../src/Notification/Infrastructure/LoggingMailer.php)
  adapter writes a `notification_log` row (what
  `GET /api/notifications?orderId=...` and the acceptance tests read) plus
  a PSR-3 line on the `notification` channel. Logged, never sent.
- [`OrderSummaryProjector`](../src/Ordering/Application/Projection/OrderSummaryProjector.php)
  inserts the `read_order_summary` row that `GET /api/orders` will list,
  and [`ProductListProjector`](../src/Catalog/Application/Projection/ProductListProjector.php)
  — triggered by the `StockReserved` events Inventory dispatched — drops
  the `available` column in `read_product_list`. Because all of it is one
  transaction, a rolled-back checkout leaves no confirmation row and no
  availability change. Ever.

### 2.5 Back out

The handler returns the `Order`; the controller renders it — note
`moneyJson()`: `{"amountMinor": 3998, "currency": "EUR"}`, no float crosses
the wire — and answers 201. One request, three contexts, one commit.

The rest of the lifecycle reuses every idea you just saw:
`POST .../payment` ([`PayOrderHandler`](../src/Ordering/Application/Command/PayOrderHandler.php), §7),
`POST .../cancel` ([`CancelOrderHandler`](../src/Ordering/Application/Command/CancelOrderHandler.php)
→ `OrderCancelled` → [`ReleaseStockOnOrderCancelled`](../src/Inventory/Application/Subscriber/ReleaseStockOnOrderCancelled.php)),
`POST .../ship` (→ `OrderShipped` →
[`CommitStockOnOrderShipped`](../src/Inventory/Application/Subscriber/CommitStockOnOrderShipped.php) —
stock actually leaves the building: `onHand` *and* `reserved` drop).
[`OrderLifecycleCest::fullHappyLifecyclePlacedPaidShipped`](../tests/Acceptance/OrderLifecycleCest.php)
walks the whole arc over HTTP and asserts the stock numbers and the three
notification rows at each step.

---

## 3. Bounded contexts: one word, three classes

The spec's first learning objective, made concrete. Open these three files
side by side:

| | [`Catalog/Domain/Product.php`](../src/Catalog/Domain/Product.php) | [`Inventory/Domain/StockItem.php`](../src/Inventory/Domain/StockItem.php) | [`Ordering/Domain/OrderLine.php`](../src/Ordering/Domain/OrderLine.php) |
|---|---|---|---|
| Answers | "what do we sell, for how much, right now?" | "how many are physically here?" | "what did this customer buy, at what price, *then*?" |
| Fields | `sku`, `name`, `description`, `price`, `active` | `sku`, `onHand`, `reserved` | `sku`, `name` (snapshot), `unitPrice` (snapshot), `quantity` |
| Mutability | mutable, every change records an event | mutable through `reserve/release/commit/setOnHand` | **immutable** — readonly properties, no mutators at all |
| Time | present tense | present tense | past tense, frozen at placement |

Same SKU, three truths — and none of them is wrong. A warehouse doesn't
care what a widget costs (`StockItem` has no price field to get stale). A
receipt must not change when marketing runs a sale (`OrderLine` snapshots
name and price; a `PATCH /api/products/WIDGET-1` price change afterwards
alters `Product`, alters what new carts see, and alters *nothing* about any
placed order). Try exactly that with curl and re-`GET` an old order.

The only vocabulary all contexts share is the shared kernel:
[`Sku`](../src/Shared/Domain/Sku.php) — whose docblock says precisely this
— plus `Money`, `Quantity`, `CustomerId`, and the event/clock/transaction
plumbing. Nothing else crosses.

### 3.1 What "no shared model" costs, and what buys it back

Ordering *does* need Catalog data (names, prices) at two moments: rendering
the cart and freezing the order. It gets them without touching Catalog's
model through an anti-corruption layer, three small files:

1. [`Ordering/Domain/Port/ProductCatalog.php`](../src/Ordering/Domain/Port/ProductCatalog.php)
   — the port, defined *by the consumer, in its own Domain*, returning
   Ordering's own [`ProductSnapshot`](../src/Ordering/Domain/Port/ProductSnapshot.php)
   VO (sku, name, unitPrice — exactly what Ordering needs, nothing more).
2. [`Catalog/Application/PublicApi/ProductCatalogQuery.php`](../src/Catalog/Application/PublicApi/ProductCatalogQuery.php)
   — Catalog's published front door. Returns plain DTOs, never entities,
   and only serves **active** products, so invariant P3 ("inactive is
   unsellable") arrives in Ordering as a simple `null`.
3. [`Ordering/Infrastructure/Acl/CatalogProductCatalog.php`](../src/Ordering/Infrastructure/Acl/CatalogProductCatalog.php)
   — the adapter joining them, translating Catalog's DTO into Ordering's
   VO. This class is the single legal cross-context call in the codebase;
   the deptrac edge `OrderingInfrastructure → CatalogPublicApi` exists for
   it alone. If Catalog reshapes its DTO, this adapter is the whole blast
   radius.

### 3.2 Everything else is events

Inventory and Notification never call anyone. They subscribe to Ordering's
`Event/` layer — the published language — and Catalog's projector consumes
Inventory's stock events the same way. Check the consumer table in
[`services.yaml`](../config/services.yaml) against PRD §5: it is the
context map, executable.

---

## 4. Aggregates and invariants: the Order's guards

An aggregate is a consistency boundary: every rule about an Order is
enforced *inside* [`Order.php`](../src/Ordering/Domain/Order.php), and there
is no way to route around it — no setters, a private constructor, children
(`OrderLine`) reachable only through the root.

Read `Order.php` with the PRD's O1–O5 in hand; the docblock maps them. The
guards, concretely:

- **Construction** is `Order::place()` only — born `placed`, never empty
  (O3), one currency (O3), identity minted internally.
- **`pay()`** refuses anything not `placed` — including a *second* pay:
  O5 says paying a paid order throws (`409`), because retry safety belongs
  at the gateway seam (§7), not in a forgiving aggregate that would mask
  double charges.
- **`ship()`** refuses anything not `paid`.
- **`cancel()`** is where the refund window lives — and nowhere else:

  ```php
  if ($this->status === OrderStatus::Paid) {
      if ($now >= $this->paidAt->add(new \DateInterval(self::REFUND_WINDOW))) {
          throw new RefundWindowClosed('Refund window has closed.');
      }
  } elseif ($this->status !== OrderStatus::Placed) {
      throw IllegalOrderTransition::attempted('cancel', $this->status);
  }
  ```

  `REFUND_WINDOW = 'PT24H'`, and the rule is `now < paidAt + 24h` — *at*
  the boundary the window is already shut. The `$now` is handed in by the
  handler from the [`Clock`](../src/Shared/Domain/Clock.php) port, which is
  the entire reason the edge is testable:
  [`OrderTest::testCancellingExactlyAtTheWindowBoundaryIsRejected`](../tests/Unit/Ordering/OrderTest.php)
  cancels at `paidAt + 24h` exactly and
  `testCancellingAPaidOrderInsideTheRefundWindowWorks` at one second
  before. Acceptance tests cannot time-travel; the unit suite owns this
  edge, honestly.
- **`total()`** is computed from the immutable lines on every call (O2).
  There is no `$total` field to drift out of sync; the invariant holds *by
  construction*, which is the strongest way an invariant can hold.

[`OrderTest`](../tests/Unit/Ordering/OrderTest.php) is the full transition
table — every legal move records its event with an exact payload, every
illegal move throws, and `testACancelledOrderRejectsEveryMutation` sweeps
the terminal state. This test file needs no container, no DB, no mocks:
that is the aggregate design paying rent.

Two smaller aggregates repeat the pattern with their own rules:
[`StockItem`](../src/Inventory/Domain/StockItem.php) (S1–S4; note
`commit()` dropping both counters) and
[`Reservation`](../src/Inventory/Domain/Reservation.php) (R2: released or
committed **at most once** — remember that guard; part 4 turns it into the
idempotency story).

And because in-process guards are check-then-act, every mutating path loads
its aggregate with a locking read (`byIdForUpdate`, `bySkuForUpdate`,
`byCustomerForUpdate`). The README's concurrency section covers the why;
[`StockConcurrencyCest`](../tests/Integration/Inventory/StockConcurrencyCest.php)
proves the row lock is real, and the DB `CHECK (0 <= reserved <= on_hand)`
backstops S1 even against a hypothetical buggy write.

---

## 5. Money: why not a float, ever

[`src/Shared/Domain/Money.php`](../src/Shared/Domain/Money.php) — integer
minor units plus an ISO code, constructed only via `Money::of(int, string)`.
No float enters (the constructor takes `int`), none leaves (JSON is
`{"amountMinor": 1999, "currency": "EUR"}` everywhere — grep `moneyJson`).

The bug class floats would buy you: IEEE 754 cannot represent most decimal
fractions, so `0.1 + 0.2 !== 0.3`, and `19.99 * 3` is
`59.96999999999999...`. In an order system those errors don't stay
theoretical — they become totals that differ from the sum of their lines by
a cent, equality checks that fail between values that "should" match, and
reconciliation drift that compounds per line, per order, per day. With int
math, `1999 * 3` is exactly `5997`; done.

Notice the operations that *don't* exist: no `multiply(float)`, no
`divide()`, no constructor from `19.99`. `multiplyBy(Quantity)` is
int × int — the API makes the unsafe thing unwritable rather than
discouraged. And every two-operand method calls `assertSameCurrency`:
adding EUR to USD throws [`CurrencyMismatch`](../src/Shared/Domain/CurrencyMismatch.php)
because this domain has no exchange rate, and a type that guesses is worse
than a type that refuses. That rule ripples outward: carts reject
mixed-currency lines (C3, [`Cart::putLine`](../src/Ordering/Domain/Cart.php)),
orders reject mixed-currency construction (O3).

Point at the tests: [`MoneyTest`](../tests/Unit/Shared/MoneyTest.php) —
`testAddingMismatchedCurrenciesThrows`, `testMultiplyByQuantity`
(1999 × 3 = 5997 exactly), `testAddIsImmutable` (operations return new
values; a VO never mutates), and the invalid-currency data provider. Even
*presentation* keeps the discipline:
[`Notification/Domain/MoneyText.php`](../src/Notification/Domain/MoneyText.php)
renders "EUR 19.99" with `intdiv` and modulo, not division.

---

## 6. Domain events: the seam — and part 4's load-bearing prep

The mechanics, end to end, all of which you already watched in §2:

1. **Record**: aggregates call `recordThat(...)` while mutating
   ([`RecordsEvents`](../src/Shared/Domain/RecordsEvents.php)). Facts, past
   tense, scalar payloads ([`DomainEvent`](../src/Shared/Domain/DomainEvent.php)
   is a pure marker).
2. **Release + dispatch**: the Application handler, *after persisting*,
   calls `dispatch(...$aggregate->releaseEvents())` on the
   [`DomainEventDispatcher`](../src/Shared/Domain/DomainEventDispatcher.php)
   port.
3. **Route**: the [`SymfonyDomainEventDispatcher`](../src/Shared/Infrastructure/SymfonyDomainEventDispatcher.php)
   adapter forwards each event under its class name; subscribers are bound
   in [`services.yaml`](../config/services.yaml).
4. **Consume**: subscribers in other contexts do their half of the story —
   synchronously, inside the emitting command's transaction.

Two honesty notes the code states in its own comments. First,
[`Cart`](../src/Ordering/Domain/Cart.php) emits no events: nothing consumes
them, and an event nobody listens to is ceremony — not every aggregate
publishes. Second, `Reservation` emits none either: the `StockItem` events
already carry everything projectors need.

Now the part that is deliberately load-bearing for part 4. **Every
`$this->events->dispatch(...)` call you have read is a synchronous function
call today and becomes an async message next part.** The dispatcher is
already a port; part 4 swaps the adapter for one that *publishes* instead
of *calling*, and the subscribers stop sharing the producer's transaction.
Read [`TransactionBoundary`](../src/Shared/Domain/TransactionBoundary.php)'s
docblock — it names its own funeral. What that one change takes away:

- **Reserve-or-rollback dies.** `InsufficientStock` can no longer un-place
  an order by throwing through the dispatcher — the order committed before
  the consumer ran. Part 4 rebuilds the guarantee with an outbox and
  compensation instead of `ROLLBACK`.
- **"Exactly once" dies.** Messages get redelivered. Reservation R2 — the
  at-most-once status guard that currently just throws on a double release
  — is exactly where redelivered `OrderCancelled` events will land, which
  is why it exists now.
- **"The projection can never disagree" dies.** Both read models are
  currently updated in the write transaction; async makes them eventually
  consistent, and `GET /api/orders` can briefly lie.

The context boundaries were drawn so that this cut lands *between*
contexts, never through one — note that the schema already has zero
cross-context foreign keys. When part 4 hurts, it will hurt along these
seams and nowhere else. That is the point of having drawn them.

---

## 7. Hexagonal for real: the payment swap

The showpiece port:
[`Ordering/Domain/Port/PaymentGateway.php`](../src/Ordering/Domain/Port/PaymentGateway.php).
Read the interface before any implementation — the *contract* is the
design. One method, `charge(OrderId, Money, PaymentMethodToken):
PaymentResult`, and the failure modes are part of the signature: a decline
is an **answer** (`PaymentResult::declined(...)` — the gateway said no),
while `PaymentGatewayTimedOut` and `PaymentGatewayUnavailable` are
**non-answers** (you don't know whether money moved). The domain forces its
callers to treat those differently, which is the difference between 422 and
502 at the HTTP edge.

The shipped adapter:
[`Infrastructure/Payment/FakePaymentGateway.php`](../src/Ordering/Infrastructure/Payment/FakePaymentGateway.php).
Behavior is selected by token — `tok_success`, `tok_declined`,
`tok_timeout_once`, `tok_error` — so every failure mode is drivable over
plain HTTP with no backdoors. It is realistically stateful: attempts
persist in `ordering_payment_attempt` (its private, infra-owned ledger), so
`tok_timeout_once` times out on the first attempt and approves on the
retry *across separate requests* —
[`OrderLifecycleCest::timeoutOnceThenSuccessfulRetry`](../tests/Acceptance/OrderLifecycleCest.php)
depends on it.

There is a second adapter in the codebase, and it proves the swap works:
[`PaymentRaceCest`](../tests/Integration/Ordering/PaymentRaceCest.php)
defines an inline anonymous `PaymentGateway` that *cancels the order
mid-charge* to simulate a race. The test injects it into the real
`PayOrderHandler` — no handler change, no domain change, a different
adapter behind the same port. That is the hexagonal claim demonstrated, not
asserted. In production wiring the swap point is one line in
[`services.yaml`](../config/services.yaml):

```yaml
App\Ordering\Domain\Port\PaymentGateway: '@App\Ordering\Infrastructure\Payment\FakePaymentGateway'
```

A real Stripe-ish adapter is a new class in `Ordering/Infrastructure/Payment`
plus this line — `Order.php`, `PayOrderHandler.php` and every test above
the integration layer stay byte-identical. (Same story for
`Notification\Domain\Mailer` → `LoggingMailer` → some day real SMTP.)

While you're in [`PayOrderHandler`](../src/Ordering/Application/Command/PayOrderHandler.php),
study the sequencing — it is the subtlest code in the project: guard
*before* the charge (never charge an unpayable order), the gateway call
**outside** any DB transaction (never hold row locks across an external
call; the fake's attempt row must survive a thrown timeout), and after an
approval, a **locked re-read** before `pay()` — because the world may have
changed while the charge was in flight. Every failure leaves the order
`placed`-and-payable, stock still reserved; retry is a cheap second POST.

### Which direction does the dependency point?

Inward, always: `Infrastructure → Domain`, never back. The interface lives
in `Domain/Port/`, the implementation in `Infrastructure/` — so the *domain
owns the contract* and infrastructure conforms. deptrac enforces the
direction mechanically: `OrderingDomain: [SharedDomain, OrderingEvents]`
has no `Vendor` and no `OrderingInfrastructure` in its allowlist, so a
domain class referencing an adapter — or any Doctrine/Symfony class — is a
build failure.

**Try it** (30 seconds): add a real vendor reference to the domain —

```php
// inside src/Ordering/Domain/Order.php
use Doctrine\ORM\EntityManagerInterface;
private ?EntityManagerInterface $em = null;
```

— then `docker compose exec php vendor/bin/deptrac`: 1 violation reported
(OrderingDomain depending on Vendor), exit code 1, CI stage red.
Revert; green. (deptrac counts real code references — a bare unused `use`
line is not enough to trip it, so give it the typed property.) This is the
spec's done-when, and it is why the Doctrine mappings are XML files under
[`config/doctrine/`](../config/doctrine/Ordering/Order.orm.xml) instead of
attributes on the entities: an `#[ORM\Entity]` attribute *is* a vendor
dependency.

---

## 8. CQRS-lite: where it earns its keep, and where it's ceremony

Two read models, both projected synchronously (§2.4), both flat tables with
no aggregates behind them. The honest section: they are not equally
motivated.

**`read_product_list` earns its keep.** `GET /api/products` must show
price *and* availability — Catalog data joined with Inventory data. A
request-time SQL join onto `inventory_stock_item` is exactly what
[`ProductListQuery`](../src/Catalog/Infrastructure/Persistence/ProductListQuery.php)
did until stage 3, and its docblock preserves the confession: that join was
a cross-context read *that deptrac could not see*, because it happened in
SQL instead of class dependencies. The projection moves the composition to
write time, through both contexts' **published events** (the
[`ProductListProjector`](../src/Catalog/Application/Projection/ProductListProjector.php)
never looks anything up — every stock event carries `available` in its
payload), leaving a single-table SELECT at request time. The boundary is
respected in the database, not just the namespace tree.

**`read_order_summary` mostly earns it.** History is a flat
status/total/line-count listing; hydrating full `Order` aggregates — lines,
VOs and all — to render a table is waste.
[`OrderHistoryQuery`](../src/Ordering/Infrastructure/Persistence/OrderHistoryQuery.php)
reads the projection, newest first, done.

**And the ceremony line is drawn on purpose:** product *detail*
(`GET /api/products/{sku}`) and order *detail* (`GET /api/orders/{id}`)
read the **write model** — look at `OrderController::show()` calling the
repository directly. Nothing is projected that doesn't need to be. Truthfully:
in a monolith on one schema, both listings could be SQL joins and no user
would notice; at this scale the *performance* argument for CQRS is nearly
zero. What you are buying here is (a) a legal composition across a context
boundary, and (b) the projection *mechanics* — event-fed, rebuildable,
write-time-composed read tables — learned while a same-transaction safety
net still holds. CQRS pays rent when read and write shapes truly diverge or
live in different stores. Part 4 does exactly that to these two tables,
and then the machinery you can currently verify with
[`OrderSummaryProjectionCest`](../tests/Integration/Ordering/OrderSummaryProjectionCest.php)
stops being optional.

---

## 9. Questions to sit with

The quiz will echo these. Don't look answers up first — reason from what
you read, then verify in the code.

1. Marketing halves the price of `WIDGET-1` at noon. A cart added it at
   11:00; an order placed it at 11:59. What price does the cart's `GET`
   show at 12:01, what price is on the placed order, and which two design
   decisions (name them by invariant letter) produce those two different
   answers? What would the "price-at-add-time" alternative have silently
   done instead?
2. Walk `POST /api/orders` for a three-line cart whose *third* line has too
   little stock: name each file the execution passes through, say what has
   been written to the database at the moment `InsufficientStock` is
   thrown, and what is in the database — and in the HTTP response — after.
   Which class turned the exception into a 409, and why could
   `OrderController` not have done it?
3. `PayOrderHandler` calls the gateway outside any transaction, then
   re-reads the order `FOR UPDATE` before `pay()`. Construct the concrete
   interleaving (there is a test that builds it) where skipping the locked
   re-read overwrites a `cancelled` order with `paid` — and explain what
   the handler does instead, and what's left in
   `ordering_payment_attempt` for reconciliation.
4. An order was paid at 13:00:00 yesterday. Cancelling at 12:59:59 today
   succeeds; at 13:00:00 it fails. Which line of which file makes the
   boundary exclusive, which unit test pins each side, and why can no
   acceptance test cover this?
5. `Money` has `multiplyBy(Quantity)` but no `multiply(float)` and no
   constructor from `19.99`. For each missing member, give the concrete
   money bug it makes unwritable, and name the `MoneyTest` case closest to
   proving it.
6. Inventory's `ReserveStockOnOrderPlaced` imports `OrderPlaced` — a class
   in Ordering's namespace — yet the deptrac law holds. Which layer split
   makes that legal, what may the event's payload contain because of it,
   and what exactly would go red if the subscriber imported
   `Ordering\Domain\OrderId` instead?
7. The system's one synchronous cross-context call runs
   `CatalogProductCatalog → ProductCatalogQuery`. Why is the port defined
   in *Ordering's* Domain rather than Catalog exposing an interface, why
   does it return `ProductSnapshot` instead of `ProductSummary`, and which
   single deptrac ruleset line permits the adapter?
8. Every `dispatch()` call in the handlers becomes an async message in
   part 4. List the three guarantees this codebase currently gets from
   same-transaction dispatch, and for each, point to the class whose
   current design is the seed of its part-4 replacement (one of them is
   `Reservation`).
9. `read_product_list` exists partly because of a bug deptrac *couldn't*
   catch. What was the violation, why was it invisible to a class-level
   dependency analyser, and how does projecting at write time fix the
   boundary rather than merely the query plan?
10. Product detail and order detail read the write model while the two
    listings read projections. Defend the split: what would projecting
    order detail cost, what does projecting the listings buy, and at what
    part-4 change does the answer to "is this ceremony?" flip?

When you're done reasoning, the fastest verification loop is still the one
from parts 1–2: `docker compose exec php vendor/bin/codecept run Unit`,
find the Cest or test that pins the behavior, and read it as the answer
key.
