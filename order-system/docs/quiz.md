# Part 3 quiz — order system

Curriculum step 3. Every question is grounded in the actual code in this
directory — when a question names a file, class, or test, open it. As in the
part 2 quiz, the point is not recall; it is being able to defend *why the
code is shaped the way it is*, and to predict what breaks when the shape
changes. Part 3's shapes are almost all DDD shapes, so most questions boil
down to: which rule is this structure protecting, and from whom?

Answers (with file references) are in [quiz-answers.md](quiz-answers.md).
Attempt everything before looking.

---

## Bounded contexts & the shared kernel

**Q1.** `src/Catalog/Domain/Product.php`, `src/Inventory/Domain/StockItem.php`
and `src/Ordering/Domain/OrderLine.php` are three different classes that all
model "a thing we sell", agreeing only on the `Sku`. A reviewer calls this a
DRY violation and proposes one shared `Product` entity with `price`, `onHand`,
`reserved` and `name` on it. Using what each class deliberately *doesn't*
know (name the fields each one lacks), explain why the "duplication" is the
design — and give two concrete bugs or couplings the merged entity would
invite in *this* codebase.

**Q2.** `Money` (`src/Shared/Domain/Money.php`) is an `int` of minor units
plus a currency string, and the docblock says no float ever enters or leaves
it — the rule extends all the way to JSON (`{"amountMinor": 7500}`) and even
email bodies (`src/Notification/Domain/MoneyText.php`). Suppose one real
float sneaks in: unit price stored as `19.99` and converted with
`(int) ($price * 100)` at checkout. Walk the arithmetic of a 3-unit order
and name the exact wrong number that comes out. Then point at the *first*
place in this codebase where the corruption would surface — think about
invariant O2, `Money::equals()`, and what
`tests/Acceptance/OrderLifecycleCest.php` asserts about totals.

**Q3.** `Cart` (`src/Ordering/Domain/Cart.php`) stores `sku => quantity` and
*nothing else* — no prices, no names (invariant C2), though it does remember
one `currency` string. Why is price-at-add-time, the obvious alternative,
rejected — what does it silently do to a cart held for a week? Which class
finally freezes prices, at what moment, and through which port do "today's"
prices arrive at that moment? And what is the currency string doing there if
the cart refuses to store money?

## Aggregates & invariants

**Q4.** Every aggregate in `src/*/Domain/` mutates only through
intention-revealing methods — `Order::pay()`, `StockItem::reserve()`,
`Cart::putLine()` — and there is not a single public setter. Suppose a
helpful refactor adds ordinary setters (`setStatus()`, `setOnHand()`,
`setReserved()`, `setLines()`). Which invariant breaks *first*, and why is it
specifically the multi-field ones that field-level setters cannot defend?
Then name the second casualty — the thing that silently stops happening on
every setter-based mutation even before any invariant is violated, and the
three downstream consumers that would drift because of it.

**Q5.** `Order::place()` calls `OrderId::generate()` (UUIDv7, minted in
`src/Ordering/Domain/OrderId.php`) instead of letting MySQL auto-increment an
id. The docblock says why; reconstruct the argument. What exactly would go
wrong with the `OrderPlaced` event's payload under DB-generated identity, and
why is that fatal given *when* the event is recorded versus when the row is
flushed? (Bonus: why UUID**v7** rather than v4 — look at what
`ordering_order` is indexed on.)

**Q6.** `Reservation` (`src/Inventory/Domain/Reservation.php`) has a status
guard: `release()` and `commit()` throw `ReservationAlreadyFinalized` unless
the reservation is still `active` (R2), and `order_id` carries a unique index
(R1). Yet in part 3's synchronous, in-process world, the acceptance test
`OrderLifecycleCest::doubleCancellationIs409()` never even reaches R2 —
something else stops the second cancel earlier. What stops it, why does R2
exist anyway, and why does the docblock call the guard "the seed of part 4's
idempotency lesson"? Also: why is `Reservation::$orderId` a plain `string`
and not Ordering's `OrderId`?

## The event seam

**Q7.** `PlaceOrderHandler` never mentions Inventory. It persists the order,
then dispatches `$order->releaseEvents()`, and
`src/Inventory/Application/Subscriber/ReserveStockOnOrderPlaced.php` does the
reserving. Explain why the handler doesn't just inject
`StockItemRepository` and reserve directly — three reasons, please: the
`deptrac.yaml` rule that makes the direct call illegal (quote the
`OrderingApplication` allowlist), the coupling argument (what Ordering would
have to know about Inventory's rules), and the part 4 payoff (what happens
to this exact seam when events go async, and why *this* code layout survives
that change almost untouched).

**Q8.** Checkout's whole story runs inside
`TransactionBoundary::transactional()` (`src/Shared/Domain/TransactionBoundary.php`)
— order insert, cart clear, and the `OrderPlaced` subscribers all commit or
roll back together. Which acceptance test proves the rollback half, and what
three "nothing happened" facts does it assert after the 409? Why does the
PRD (and the `TransactionBoundary` docblock) make a point of saying part 4
*takes this guarantee away* — what do you get instead, and why is it worth
the loss?

**Q9.** Every Inventory stock event — `StockReserved`, `StockReleased`,
`StockCommitted`, `StockReceived` — carries the post-change `available`
count in its payload. `ProductListProjector`
(`src/Catalog/Application/Projection/ProductListProjector.php`) is the only
consumer. Why must the availability travel *in the payload* — what would the
projector otherwise have to do, and name the two distinct reasons it can't
(one is a deptrac rule; one is a part 4 problem)?

**Q10.** `InsufficientStock` is thrown by Inventory's domain, erupts through
Ordering's checkout, and becomes a 409 — yet `OrderController` has no catch
block for it, and `PlaceOrderHandler`'s docblock pointedly refuses even an
`@throws` reference to it. Explain the mechanism that gets it to HTTP
(`src/Shared/Domain/StateConflict.php` +
`src/Shared/Infrastructure/Http/JsonExceptionListener.php`), and why a
shared marker interface is the only shape that keeps the deptrac arrows
legal. While you're in the listener: why do *unmapped* domain exceptions
deliberately become 500s instead of some catch-all 4xx?

## Hexagonal & the deptrac law

**Q11.** `OrderRepository`, `StockItemRepository`, `CartRepository`,
`PaymentGateway`, `ProductCatalog`, `Mailer`, `Clock`, `TransactionBoundary`
— all interfaces defined in a `Domain/` directory, all implemented in an
`Infrastructure/` directory. Name the dependency arrow this inverts (what
would point at what in the naive layout), and give three payoffs visible in
this repo: one about `tests/Unit/`, one about what
`OrderRepository::byIdForUpdate()`'s docblock manages to make part of the
*contract*, and one about how much of the codebase a real payment provider
would touch.

**Q12.** PHPStan runs at level 8 with no baseline and stays green. Write one
line of PHP you could add to `src/Ordering/Domain/Order.php` that PHPStan
would happily accept but that turns the Jenkinsfile's "Deptrac (architecture
gate)" stage red, and explain the different question each tool answers. Then the honest counterpart: the
README admits the pre-stage-3 product listing contained a genuine boundary
violation that deptrac *couldn't* see. What was it, why was it invisible to
a class-dependency analyser, and what construct removed it?

**Q13.** Ordering needs Catalog's prices at checkout, but
`PlaceOrderHandler` depends on `ProductCatalog`
(`src/Ordering/Domain/Port/ProductCatalog.php`) returning Ordering's own
`ProductSnapshot` — while the adapter
(`src/Ordering/Infrastructure/Acl/CatalogProductCatalog.php`) calls
Catalog's `ProductCatalogQuery` and remaps its DTO field by field. That
remapping looks like busywork: `sku`, `name`, `priceMinor` in;
`sku`, `name`, `unitPrice` out. Defend it anyway: what does the indirection
buy when Catalog reshapes its published DTO, which deptrac rules force the
call to happen in *Infrastructure* rather than the handler, and where does
invariant P3 ("inactive products are unsellable") actually get enforced in
this chain?

## Payment

**Q14.** For each fake-gateway token —
`tok_declined`, `tok_timeout_once` (first attempt), `tok_error`, `tok_visa` —
give the HTTP status the client sees *and* the state the order is left in.
Then defend the uniform second half of every answer: why does **every**
payment failure leave the order `placed`-and-payable with stock still
reserved, instead of rolling the placement back? The PRD gives three
reasons; the acceptance test
`OrderLifecycleCest::declinedPaymentLeavesTheOrderPayable()` shows the
payoff. What known real-world cost does this choice accept (the PRD names
it and declares it out of scope)?

**Q15.** `PayOrderHandler` has a deliberate and slightly odd shape: guard →
`charge()` **outside** any transaction → only on approval, open a
transaction that **re-reads the order under a row lock** before calling
`pay()`. Three questions: (a) why must the gateway call sit outside the
transaction — one reason is a general rule, one is specific to how
`tok_timeout_once` works across two HTTP requests; (b) what concrete race
does the locked re-read close — describe the interleaving where the
pre-charge `assertPayable()` lies; (c) after an *approved* charge fails the
re-check and 409s, what is left behind in `ordering_payment_attempt`, and
what would a real PSP adapter do at that point?

## CQRS-lite

**Q16.** This project has exactly two read models (`read_product_list`,
`read_order_summary`), and exactly two detail endpoints that deliberately
*don't* use them — plus one aggregate that publishes no events at all. For
each of the two projections, say what it earns its keep by (the
`read_product_list` argument involves deptrac; the `read_order_summary` one
involves hydration cost). Then locate the ceremony that was deliberately
dropped: why do `GET /api/products/{sku}` and `GET /api/orders/{id}` read
the write model, and why does `Cart` emit nothing? Finish with the README's
one-line rule for when CQRS starts paying rent.

## Concurrency & tests

**Q17.** `tests/Acceptance/ConcurrentCheckoutCest.php` fires genuinely
parallel checkouts with `curl_multi` (the REST module is sequential) and
asserts sorted status codes `[201, 409]` for two customers racing the last
unit, and `[201, 422]` for one customer checking out the same cart from two
devices. Different locks win each race. Name the locking repository read
that serializes each scenario, explain what the loser re-reads that produces
its particular status code, and say why the in-PHP guards (`S2`, the
empty-cart check) are worthless without `SELECT ... FOR UPDATE` underneath
them.

**Q18.** `OrderLifecycleCest` rarely stops at asserting a status code: after
checkout it asserts `{"onHand": 10, "reserved": 3, "available": 7}`, after
shipping `{"onHand": 7, "reserved": 0}`, after a failed checkout that stock
is untouched *and the cart still has its line*. Why are the stock-count
assertions the actual test — what specific, realistic breakage would a
200s-only suite wave through? (Hint: how are the event subscribers wired,
and which static tool checks that wiring? None does — that's the point.)
And why is the refund-window *expiry* path — `RefundWindowClosed` at
exactly +24h — absent from the acceptance suite and tested only in
`tests/Unit/Ordering/OrderTest.php`?
