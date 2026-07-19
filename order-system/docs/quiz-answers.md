# Part 3 quiz — answers

Questions are in [quiz.md](quiz.md). File paths are relative to
`order-system/`.

---

## Bounded contexts & the shared kernel

### A1. Three "products", zero duplication

What each class deliberately lacks:

- **`Catalog\Domain\Product`** — SKU, name, description, price, active flag;
  no `onHand`, no `reserved`, no order references. Its docblock says it
  plainly: stock is "Inventory's problem", buyers are "Ordering's problem".
- **`Inventory\Domain\StockItem`** — SKU, `onHand`, `reserved`; no price, no
  name, no description. "A warehouse doesn't care what things cost."
- **`Ordering\Domain\OrderLine`** — SKU, name *snapshot*, unit-price
  *snapshot*, quantity; no live catalog price and no stock numbers. It is
  contractual truth at the moment of sale.

It isn't duplication because the three classes answer three different
questions (*what do we sell it for*, *how many can we promise*, *what did
this customer actually buy*) that change for different reasons, on different
schedules, under different invariants (P1–P3 vs S1–S4 vs O2/O3). DRY is
about not repeating *knowledge*; the only shared knowledge here is the SKU —
and that, plus `Money`/`Quantity`, is exactly what lives in
`src/Shared/Domain/`.

Two concrete failures of the merged entity in this codebase:

1. **Price changes rewrite history.** `OrderLine` holds a snapshot precisely
   so that `PATCH /api/products/{sku}` with a new `priceMinor` cannot touch a
   placed order (`Order`'s docblock: "a later catalog price change never
   rewrites a past order"). If order lines pointed at the one shared entity,
   every re-price would silently re-price past orders — and
   `Order::total()`, computed from lines (O2), would change retroactively.
2. **One lock, four contexts.** Every mutation path takes row locks
   (`StockItemRepository::bySkuForUpdate`, etc.). A merged entity would put
   Catalog renames, checkout reservations and admin re-prices behind the
   *same* row, so a merchandiser editing a description would contend with —
   and serialize against — every checkout of that SKU. Split, Inventory
   locks `inventory_stock_item` and Catalog edits `catalog_product`, and
   never meet. (It would also collapse the part 4 fault line: the tables are
   context-prefixed with zero cross-context FKs so the schema can be split;
   a shared entity is the one thing you cannot cut.)

### A2. One float, one lost cent

`19.99` has no exact binary representation. In IEEE 754,
`19.99 * 100 === 1998.9999999999998`, so the innocent conversion
`(int) ($price * 100)` truncates to **1998** minor units. A 3-unit order
snapshots `unitPriceMinor = 1998` into its `OrderLine`s and
`Order::total()` faithfully computes 3 × 1998 = **5994** — the customer is
charged 59.94 for 59.97 of goods, one cent short per unit, forever, in the
immutable contractual record. (The other classic: `0.1 + 0.2 === 0.3` is
`false`, so any float-based equality is a coin toss.)

Where it surfaces first: `Money::equals()` and everything built on exact
integer identity. Concretely:

- `tests/Acceptance/OrderLifecycleCest::fullHappyLifecyclePlacedPaidShipped()`
  asserts `'total' => ['amountMinor' => 7500]` for 3 × 2500 — exact-int
  assertions like this are only writable at all because the arithmetic is
  exact. With float-derived minors, which prices break and which survive
  becomes a per-price lottery decided by binary representability.
- Invariant O2 (`Order::total()` "computed, never stored, cannot drift")
  only holds because `Money::add()`/`multiplyBy()` are integer ops. Float
  addition is not even associative, so "computed from lines" would give
  different answers depending on summation order.
- Subtlest: `Product::changePrice()` skips the no-op case via
  `$this->price->equals($price)` — "events are facts, not echoes". Float
  jitter would make equal prices compare unequal, emitting phantom
  `ProductPriceChanged` events and churning the `read_product_list`
  projection with non-changes.

Hence the rule's reach: `Money::of()` accepts only `int`; JSON carries
`{"amountMinor": 7500, "currency": "EUR"}` (`OrderController::moneyJson()`);
even `Notification\Domain\MoneyText::format()` renders "EUR 75.00" with
`intdiv()` and `%` — integer arithmetic to the last mile.

### A3. Carts hold intent, orders hold contracts

Price-at-add-time means a cart held for a week checks out at week-old
prices: the shop *silently sells at stale prices*, and either eats the
difference or — worse — honors a price the merchandiser withdrew days ago.
`Cart`'s C2 comment states the trade-off; the chosen rule is "a cart checks
out at *today's* price; the ORDER then freezes it."

The freeze happens in `PlaceOrderHandler::handle()`: inside the checkout
transaction it resolves each cart line through the **`ProductCatalog` port**
(`src/Ordering/Domain/Port/ProductCatalog.php`, adapter
`CatalogProductCatalog` → Catalog's `ProductCatalogQuery`), getting a
`ProductSnapshot` (name + `Money` unit price *now*), and hands those to
`Order::place()`, which bakes them into immutable `OrderLine`s. Same port,
same rule, at read time: `GetCartViewHandler` prices `GET /api/cart` live,
so "what you see here is what checkout would freeze right now."

The lone `currency` string is not a price — it exists to enforce **C3** (all
lines one currency) at *add* time: `Cart::putLine()` throws
`CartCurrencyMismatch` ("Cart currency mismatch." → 422) if a product priced
in another currency joins, because `Money::add()` across currencies throws
`CurrencyMismatch` and a mixed cart could never be totalled or checked out.
It's remembered from the first line and forgotten when the cart empties
(`removeLine()`/`clear()` null it out — an empty cart is currency-agnostic).

## Aggregates & invariants

### A4. Setters kill the relational invariants first — and the event stream before that

First to break: the **multi-field invariants**, and S1
(`0 ≤ reserved ≤ onHand`) is the sharpest case. `setOnHand(5)` and
`setReserved(9)` are each individually reasonable calls; no field-level
setter can see that *together* they violate the relation. The real
`StockItem` defends S1 by only exposing *transitions* — `reserve()` checks
`available()`, `release()`/`commit()` check `reserved`, `setOnHand()`
rejects values below `reserved` (S4) — every method guards the relation
between fields, which is exactly what a per-field setter cannot do. Same
family: O1 needs status + `paidAt` + the clock (`setStatus('cancelled')`
can't know about the refund window), and O2 survives only because there *is
no* stored total to set — `Order::total()` recomputes from immutable lines.

The second casualty precedes any violated invariant: **events stop being
recorded**. `recordThat()` calls live inside the mutators
(`Order::pay()` records `OrderPaid`; `StockItem::reserve()` records
`StockReserved`). A setter-based mutation is invisible to the seam, so three
consumers silently drift out of reality:

1. **Inventory** — an order "cancelled" via setter emits no
   `OrderCancelled`, so `ReleaseStockOnOrderCancelled` never runs and the
   reservation leaks forever;
2. **Notification** — no `OrderPaid`, no receipt row in `notification_log`;
3. **the projections** — `OrderSummaryProjector` / `ProductListProjector`
   never hear about it, so `GET /api/orders` and `GET /api/products` show a
   world that no longer exists.

(And no, persistence doesn't need setters: Doctrine hydrates private fields
by reflection, and the one hand-rolled rehydration path —
`Cart::restore()` — re-validates every row through `Sku`/`Quantity` VOs so
garbage rows cannot become a Cart.)

### A5. Identity is minted where the event is recorded

`Order::place()` records `OrderPlaced` *in the constructor path*, before any
repository or flush is in sight — and the payload's first field is
`$order->id->value`. With auto-increment identity the id does not exist
until the INSERT returns, so at recording time the aggregate would have
nothing to put in the event (or would need the persistence layer to reach
back in and patch the event afterwards — persistence mutating domain facts).
Domain-minted UUIDv7 (`OrderId::generate()` → `Shared\Domain\UuidV7`)
inverts it: the aggregate owns its identity from the first instant, events
are complete at the moment of recording, and the whole
record-then-persist-then-dispatch flow in `PlaceOrderHandler` stays one-way.

`UuidV7`'s docblock adds two grounded details: it's hand-rolled because
`symfony/uid` in the domain would be a deptrac violation (vendor import in
`SharedDomain`), and it's **v7** rather than v4 because the 48-bit
millisecond prefix keeps ids time-ordered — `ordering_order`'s CHAR(36)
primary key is InnoDB's clustered index, so ordered ids append instead of
splatter-inserting random pages.

### A6. R2 is a loaded gun for part 4

What actually stops the double cancel: **the Order's own state machine**.
The second `POST /cancel` goes through
`CancelOrderHandler` → `Order::cancel()`, which finds status `cancelled`
(terminal, O1) and throws `IllegalOrderTransition` → 409 — no second
`OrderCancelled` event is ever emitted, so `ReleaseStockOnOrderCancelled`
never runs twice. In part 3's synchronous world, R2 is unreachable through
the API (defense in depth at most).

Why it exists anyway: part 4 replaces in-process dispatch with a broker and
**at-least-once delivery** — the *same* `OrderCancelled` event genuinely
arriving twice, with no upstream state machine to deduplicate it. Then the
status guard in `Reservation::release()`/`commit()`
(`ReservationAlreadyFinalized` unless `active`) is the only thing standing
between a redelivered event and releasing the same stock twice. Writing the
guard now means the handler is *already* shaped for redelivery — that is
"the seed of part 4's idempotency lesson". R1 (unique `order_id` index) is
the same belt-and-braces at the schema level: at most one reservation per
order, enforced by the database rather than a racy pre-check.

`$orderId` is a plain `string` because the id crosses the context boundary
*as a value*, part of Ordering's published language (the `OrderPlaced`
payload), not as a type. Importing `Ordering\Domain\OrderId` would make
`InventoryDomain` depend on `OrderingDomain` — not in `deptrac.yaml`'s
allowlist (`InventoryDomain: [SharedDomain, InventoryEvents]`), build red.

## The event seam

### A7. Why the handler doesn't just call Inventory

1. **It's illegal.** `deptrac.yaml`:
   `OrderingApplication: [OrderingDomain, OrderingEvents, SharedDomain]` —
   no `InventoryDomain`, no `InventoryApplication`. Injecting
   `StockItemRepository` into `PlaceOrderHandler` is a dependency not on the
   allowlist; the "Deptrac (architecture gate)" CI stage goes red. The
   arrows only flow the other
   way: `InventoryApplication: [..., OrderingEvents]` — Inventory may
   *listen* to Ordering's published language, Ordering may not *reach into*
   Inventory.
2. **Coupling.** Reserving correctly means knowing Inventory's rules: lock
   the row (`bySkuForUpdate`), check S2, create a `Reservation` (R1),
   dispatch the stock events. Inline that in `PlaceOrderHandler` and
   Ordering owns warehouse policy; every Inventory change ships with an
   Ordering change. With the seam, `ReserveStockOnOrderPlaced` consumes
   only the event's scalars ("conformist" — it builds its own `Sku`/
   `Quantity` from the payload) and each context's rules stay home.
3. **Part 4 survives.** The seam is already the final shape:
   `Order` records facts (`RecordsEvents`), the handler releases them into
   the `DomainEventDispatcher` **port**, a subscriber consumes them. When
   part 4 makes dispatch async, the adapter behind that port changes
   (outbox + broker instead of `SymfonyDomainEventDispatcher`), and the
   subscriber gains redelivery handling — but `Order`, `PlaceOrderHandler`
   and `ReserveStockOnOrderPlaced` keep their shapes. A direct call has no
   seam to swap; it would have to be torn apart and rewritten as... exactly
   this event flow.

What part 3 buys *now*: the transaction (A8) — and testability, since the
whole flow is observable as "which events came out" (`releaseEvents()` is
asserted directly in `OrderTest::testPlaceRecordsOrderPlacedWithExactPayload`).

### A8. Reserve-or-rollback, and its scheduled demolition

The proof is
`OrderLifecycleCest::insufficientStockRejectsCheckoutAtomically()`: cart of
5 against `onHand: 2`, `POST /api/orders` → 409
`"Insufficient stock for <sku>."`, then three nothing-happened assertions:

1. stock untouched — `{"onHand": 2, "reserved": 0, "available": 2}`;
2. **the cart still holds its line** — the customer can fix the problem and
   retry (the test raises stock and checks out the *same cart* successfully);
3. no order was created (the retry's 201 is the first).

Implicitly it also proves no partial reservation survived: `OrderPlaced`
dispatch, the `read_order_summary` insert, the notification row and the
order INSERT all lived in the one `transactional()` closure, so
`InsufficientStock` erupting from the subscriber vaporizes all of it.

Part 4 takes this away because the transaction *is* the coupling: one DB
connection, one commit, everything synchronous and co-located. The moment
Inventory consumes events asynchronously (or from another service), there is
no shared transaction left — you get an outbox (events commit atomically
*with* the order, delivery happens later) and **compensation** (placement
succeeds, reservation later fails, so a compensating "cancel the order"
flow runs) instead of rollback. The trade is worth it for the decoupling —
Ordering no longer waits on, or dies with, Inventory — but the ACID
guarantee this test pins down is precisely the tuition part 4 charges.

### A9. Payloads carry `available` so projectors never look back

Without it, `ProductListProjector::onStockChanged()` would have to *look up*
the current availability — query `inventory_stock_item` or call some
Inventory API — to write `read_product_list.available`. Two independent
vetoes:

1. **The deptrac law.** The projector is `CatalogApplication`, whose
   allowlist is `[CatalogDomain, CatalogEvents, SharedDomain,
   InventoryEvents]` — it may consume Inventory's *events*, but touching
   Inventory's repositories (`InventoryDomain`) or tables is exactly the
   cross-context reach the ruleset exists to forbid. The event payload is
   the published language; a lookback is a hand through the wall.
2. **Part 4.** Today a lookback would even work — same process, same
   schema, same transaction. Async, it becomes a race: by the time the
   event is consumed, the row reflects *later* events, so a delayed
   `StockReserved` would project tomorrow's availability against
   yesterday's fact (and replaying events to rebuild the projection would
   read only end-state, making rebuilds wrong). A self-contained payload
   makes the event a complete fact wherever and whenever it lands.

That's why all four stock events (`StockReceived`, `StockReserved`,
`StockReleased`, `StockCommitted`) carry the post-change `available` —
`StockItem` computes it at recording time, where it is unambiguous.

### A10. One marker, one listener, legal arrows

Mechanism: `InsufficientStock extends \DomainException implements
StateConflict`. `StateConflict` (`src/Shared/Domain/StateConflict.php`) is
an empty marker interface in the *shared kernel* meaning "the request is
valid but conflicts with current state" — the PRD's pinned 409 rule.
`JsonExceptionListener` (on `kernel.exception`, priority −8) checks
`$throwable instanceof StateConflict` first and renders the one error shape
`{"errors":[{"field":null,"message":...}]}` with status 409. So the
exception flies: `StockItem::reserve()` → through the dispatcher → through
`PlaceOrderHandler` → past the controller (no catch) → listener → 409.

Why a shared marker is the only legal shape: the thrower is
`Inventory\Domain`, the HTTP edge is `Ordering\Infrastructure`. A
`catch (InsufficientStock $e)` in `OrderController` needs the import
`App\Inventory\Domain\InsufficientStock` — and `OrderingInfrastructure`'s
deptrac allowlist has no Inventory entry, so the build is red
(`OrderController`'s docblock: it "could not even import Inventory's
`InsufficientStock` legally"). With the marker, every arrow points at
`SharedDomain`, which everyone may see; the listener maps *any*
`StateConflict` without knowing which context threw it. Same reason
`PlaceOrderHandler` refuses the `@throws` annotation: a docblock type
reference is still a dependency, and the point of the seam is that Ordering
doesn't know Inventory's exception types exist.

Unmapped domain exceptions → 500 on purpose (the listener's docblock):
choosing a status code for a domain rule is an HTTP-edge decision, and an
exception nobody mapped means nobody made that decision — a bug. A blanket
"unknown domain exception → 4xx" would dress bugs up as client errors and
hide them; a loud 500 gets them fixed.

## Hexagonal & the deptrac law

### A11. The arrow flips: domain defines, infrastructure conforms

Naive layering points the domain at its tools: `Order` (or its service)
depends on Doctrine's `EntityManager`, business logic imports persistence.
Ports invert it — **`Ordering\Domain\OrderRepository` is defined by the
domain in the domain**, stating what the aggregate *requires* of any
persistence; `Ordering\Infrastructure\Persistence\DoctrineOrderRepository`
depends on the interface, not the other way. Infrastructure → Domain,
never Domain → Infrastructure. (Deptrac makes it law: `OrderingDomain:
[SharedDomain, OrderingEvents]` — a domain import of Doctrine is red.)

Three payoffs in this repo:

1. **`tests/Unit/` exists as written** — pure-domain tests with no
   container, no DB, no mocks-of-Doctrine: `OrderTest` walks the entire O1
   transition table with a fixed clock, `StockItemTest` covers S1–S4,
   because the domain's only outward references are its own interfaces.
2. **The locking discipline is part of the contract.**
   `OrderRepository::byIdForUpdate()`'s docblock — "every state transition
   must go through this... two concurrent requests could both pass a guard
   on stale state" — lives *on the port*, so any adapter (Doctrine today,
   anything tomorrow) inherits the requirement, and callers can see the
   concurrency rule where they choose the method. Same on
   `StockItemRepository::bySkuForUpdate()` and
   `CartRepository::byCustomerForUpdate()`.
3. **A real PSP touches one directory.** `PaymentGateway` is an Ordering
   *domain* port; `FakePaymentGateway` is one Infrastructure class plus its
   private `ordering_payment_attempt` ledger ("swapping in a real PSP
   deletes it along with this class"). `PayOrderHandler`, `Order::pay()`,
   every test above integration level: untouched. That is the spec's
   hexagonal claim made demonstrable.

### A12. Different questions: "is it type-correct?" vs "may it exist?"

The one line:

```php
use Doctrine\ORM\EntityManagerInterface;
```

(injected or not — the import alone is the dependency) in
`src/Ordering/Domain/Order.php`. PHPStan level 8: perfectly fine — the
class exists, the types check, nothing is unsafe. Deptrac: `OrderingDomain`
→ `Vendor` is not in the ruleset (only the four `*Infrastructure` layers
and `SharedInfrastructure` may see `Vendor`), so the "Deptrac (architecture
gate)" stage goes red. PHPStan answers
*"is this code internally sound?"*; deptrac answers *"is this dependency
allowed to exist at all?"* — correctness versus architecture. Type checkers
cannot express "this correct code is in the wrong place."

The honest counterpart (README, CQRS section): before stage 3,
`GET /api/products` was built on a SQL `LEFT JOIN` from `catalog_product`
onto **`inventory_stock_item`** — Catalog reading Inventory's table, a
boundary violation as real as any import. Deptrac never saw it because
deptrac analyses *class references*, and this dependency lived inside a SQL
string; table names aren't in the dependency graph. What removed it was the
`ProductListProjector`: Inventory publishes stock events carrying
`available` (A9), the projector composes both contexts *at write time* into
`read_product_list`, and the read query became a deptrac-clean single-table
`SELECT`. Moral: the architecture gate is necessary, not sufficient — SQL,
config and conventions still need eyes.

### A13. The ACL's busywork is the point

What the remapping buys: **a blast-radius boundary**. `PlaceOrderHandler`
and `GetCartViewHandler` depend on `ProductCatalog` returning
`ProductSnapshot` — Ordering's own VO, in Ordering's language (`Sku` +
`Money`, not `priceMinor`/`currency` scalars). If Catalog reshapes
`ProductSummary` — renames fields, splits price, versions the DTO — the
compiler points at exactly one file: `CatalogProductCatalog`, the adapter.
The docblock says it: "this adapter is the blast radius; Ordering's domain
never notices." Without the ACL, Catalog's published shape *is* Ordering's
internal shape, and Catalog refactors ripple into another context's domain
logic — the corruption an anti-corruption layer exists to stop. It also
keeps checkout unit-testable: faking `ProductCatalog` is a five-line stub.

Why the call sits in Infrastructure: deptrac allows
`CatalogPublicApi` to exactly one consumer —
`OrderingInfrastructure: [..., Vendor, CatalogPublicApi]`. The
`OrderingApplication` allowlist (`[OrderingDomain, OrderingEvents,
SharedDomain]`) has no `CatalogPublicApi`, so `PlaceOrderHandler` calling
`ProductCatalogQuery` directly is a red build. Cross-context *calls* are an
integration concern; integration lives in the adapter ring.

P3 is enforced at **Catalog's front door**:
`ProductCatalogQuery::activeBySku()` returns `null` for missing *and* for
inactive products — consumers cannot even see an inactive product. Ordering
just interprets the `null` in its own terms: at cart-put, 422 "Unknown or
inactive product."; at checkout, `ProductNoLongerAvailable` → 409 (the
world changed since the line was added — see
`OrderLifecycleCest::checkoutWithADeactivatedProductIs409()`).

## Payment

### A14. Every failure leaves `placed` — placement never un-happens

| Token | Client sees | Order state after |
|---|---|---|
| `tok_declined` | 422 `"Payment was declined."` | `placed`, stock reserved, payable |
| `tok_timeout_once` (1st attempt) | 502 "timed out — the charge did not complete; safe to retry." | `placed`, stock reserved; attempt row persisted, retry approves |
| `tok_error` | 502, every time | `placed`, stock reserved |
| `tok_visa` | 422, field `paymentMethodToken` (well-shaped but unknown → `UnrecognizedPaymentMethod` from the fake's default arm) | `placed` — the failed attempt is still recorded |

(Whitespace-shaped garbage never reaches the gateway at all — the
`PayOrderRequest` regex catches it as a 422 at the DTO.)

Why uniformly `placed`-and-payable (PRD §6 decision, restated in
`PayOrderHandler`'s docblock):

1. **`placed` is a real state, not a moment.** The spec's machine has
   `placed → paid` as a *transition*; if payment failure undid placement,
   `placed` would be an implementation detail of a single request, not a
   durable state a customer can sit in overnight.
2. **Real gateways fail transiently; retry must be cheap.** A retry is one
   more `POST /api/orders/{id}/payment`. Rolling back placement would force
   re-running checkout — release the reservation, re-reserve, possibly lose
   the stock to someone else in between — churn with real failure modes of
   its own.
3. **It gives the tests an honest retry story.**
   `declinedPaymentLeavesTheOrderPayable()`: 422, assert still `placed`,
   pay again with `tok_success`, 200 `paid`. Same order, same reservation,
   no ceremony.

The accepted cost, named and deferred by the PRD: reserved stock sits on a
failed-payment order **indefinitely** — there is no reservation expiry/TTL.
A real shop would sweep abandoned `placed` orders; part 3 declares that out
of scope rather than pretending the problem doesn't exist.

### A15. Charge outside, re-read inside

**(a) Why `charge()` is outside the transaction.** The general rule: never
hold a DB transaction (and its row locks) open across an external network
call — a slow PSP would pin locks for seconds and stall every reader of
that row. The specific-to-this-code reason: `FakePaymentGateway` *persists
its attempt row and then throws* for `tok_timeout_once`. If the charge ran
inside the handler's transaction, the thrown `PaymentGatewayTimedOut` would
roll the attempt row back — and the second HTTP request would again count
zero prior attempts and time out forever.
`OrderLifecycleCest::timeoutOnceThenSuccessfulRetry()` (502 then 200 across
two separate requests) only passes because the attempt row survives the
throw.

**(b) The race the locked re-read closes.** `assertPayable()` runs on a
snapshot read *before* the charge; the gateway call then takes real time.
Interleaving: request A passes the guard and enters the charge; meanwhile
request B — a double-clicked pay, or a cancel — commits `paid` or
`cancelled`. Without the re-read, A would resume with its stale in-memory
entity (status still `Placed`), call `pay()`, and flush a transition the
aggregate forbids — paying a cancelled order, or double-paying. Instead the
transaction opens with `byIdForUpdate()` (exclusive row lock, fresh state)
and `pay()` re-asserts payability on *current* state: the conflict surfaces
as `IllegalOrderTransition` → 409 via `StateConflict`.
`tests/Integration/Ordering/PaymentRaceCest.php` covers this seam.

**(c) After an approved charge loses the re-check:** the customer's money
was (fake-)taken but the order can't move to `paid`. The approved attempt
row remains in `ordering_payment_attempt` — the adapter's private,
infra-owned ledger records every attempt including this orphan — so the
discrepancy is *visible for reconciliation* rather than vanished. The
handler's docblock names the real-world move: a production PSP adapter
would **void/refund the charge** at that point (and this is part 4's
compensation pattern in miniature).

## CQRS-lite

### A16. Two projections that pay rent, two endpoints that refuse to

**`read_product_list`** earns its keep by composing **two bounded contexts
without a cross-boundary read**: name/price/active from Catalog's events,
`available` from Inventory's (payload-carried, A9), merged at *write time*
by `ProductListProjector`. The request-time alternative is a join across
context-owned tables — the SQL boundary violation deptrac can't even see
(A12). The projection is what makes `GET /api/products` both useful
(price *and* availability) and legal.

**`read_order_summary`** earns its keep on shape: history is a flat
status/total/lineCount listing. Serving it from `OrderRepository` would
hydrate full `Order` aggregates — lines, VOs, the works — to render a
table row each; `OrderHistoryQuery` reads flat rows for a flat need.
(`OrderController::history()`'s comment: "flat rows for a flat listing.")

The dropped ceremony:

- **Detail endpoints read the write model.** `GET /api/products/{sku}` and
  `GET /api/orders/{id}` load the aggregate — one keyed read, no
  composition, no shape mismatch, so a projection would duplicate data for
  zero benefit. They exist partly *to prove you don't project everything*.
- **`Cart` emits no events.** Nothing consumes them; "an event nobody
  listens to is ceremony" (`Cart`'s docblock). Not every aggregate
  publishes — events are for consumers, not for DDD decor.

The README's rule: **CQRS pays rent only once read and write shapes truly
diverge or move to different stores** — which is exactly what part 4 does to
these two projections (eventually consistent, rebuilt from events).

## Concurrency & tests

### A17. Two races, two locks

**Race 1 — two customers, last unit (`[201, 409]`).** Serialized by
`StockItemRepository::bySkuForUpdate()` inside
`ReserveStockOnOrderPlaced` — `SELECT ... FOR UPDATE` on the
`inventory_stock_item` row, inside each checkout's transaction. The loser
blocks on the row lock until the winner commits, then *re-reads current
state*: `available() = 0`, so `reserve()` throws `InsufficientStock` → the
whole checkout rolls back → 409. The winner's 201 stands; the warehouse
shows `reserved: 1`, never 2.

**Race 2 — one cart, two devices (`[201, 422]`).** Serialized one step
earlier by `CartRepository::byCustomerForUpdate()` in
`PlaceOrderHandler` — the lock is on the *cart* row. The winner places the
order and `clear()`s the cart in its transaction; the loser then acquires
the lock, re-reads the **consumed** cart, finds it empty, and throws
`EmptyCart` → 422 "Cart is empty." One order, one reservation — never a
duplicate.

Why the PHP guards alone are worthless: both are **check-then-act** —
`if (available() < qty)` then mutate; `if (cart->isEmpty())` then consume.
Without the lock, two transactions interleave between check and act: both
read `available: 1`, both pass S2, both flush `reserved` — the second
UPDATE silently overwrites the first (a classic lost update), and one unit
is promised twice. The guard code is *correct* and still loses; only
serializing the read (`FOR UPDATE`) makes check-then-act atomic. That's why
every mutating path in the repos' docblocks *requires* the locking read —
and why the Cest uses `curl_multi` through nginx and separate php-fpm
workers: the race has to be real to prove the lock is.

### A18. Stock counts are the contract; 200s are the doorbell

A 201 from `POST /api/orders` proves the Ordering controller's happy path
ran. The *claims under test* — "checkout reserves stock", "cancel releases
it", "ship commits it out of the warehouse", "insufficient stock rolls
everything back" — are cross-context side effects that happen (or silently
don't) in Inventory, observable only through `GET /api/stock/{sku}`. The
realistic breakage a 200s-only suite waves through: **the event seam
quietly unwired.** Subscribers are registered in `config/services.yaml`
(Application classes carry no framework attributes — the wiring is pure
config); delete the `ReserveStockOnOrderPlaced` tag and *nothing* static
notices — PHPStan green (no types broke), deptrac green (no illegal
dependency appeared), and every endpoint still returns its happy status
code while orders stop reserving anything. Only
`{"onHand": 10, "reserved": 3, "available": 7}` after checkout, or
`{"onHand": 2, "reserved": 0}` plus the still-intact cart after the 409,
can tell the difference. The stock assertions are end-to-end proof that the
event seam — the load-bearing structure of the whole part — actually
carries load. (Same logic: the lifecycle test asserts the notification log
holds exactly `order_confirmation`, `payment_receipt`, `shipment_notice`,
in order.)

Refund-window expiry is unit-only because acceptance tests live in real
time: over HTTP, "paid 24 hours and one second ago" requires either a
24-hour sleep or a backdoor to fake time — and this project pointedly has
no test backdoors (the gateway is driven by tokens over plain HTTP, not
magic). The domain took the testability route instead: `cancel()` compares
against an *injected* `\DateTimeImmutable` from the `Clock` port, so
`tests/Unit/Ordering/OrderTest.php` hands in any instant it likes —
`testCancellingAPaidOrderInsideTheRefundWindowWorks`,
`testCancellingExactlyAtTheWindowBoundaryIsRejected` (at exactly
`paidAt + PT24H` the window is already closed — the guard is `>=`), and
`testCancellingWellAfterTheWindowIsRejected`. The acceptance suite covers
the *reachable* halves — cancel-while-placed and cancel-paid-seconds-ago —
and the PRD says the split out loud: HTTP tests cannot time-travel.
