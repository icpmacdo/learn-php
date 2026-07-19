# Part 3 — Exercises

Curriculum step 5: **you implement, Claude reviews.** Eight modification tasks
against the finished order system, ordered easy → hard. Each one extends
something that already exists, so the first move is always the same: read the
file(s) named in the exercise and the tests that pin their current behavior.

The rules, same as the rest of part 3:

- Everything runs inside the `php` container
  (`docker compose exec php vendor/bin/codecept run`, `.../deptrac`,
  `.../phpstan analyse`, `.../php-cs-fixer fix`). Nothing on the host.
- **Definition of done is a green Jenkins build** (`http://localhost:8084`,
  *order-system → Build Now*). The pipeline judges the working tree, so you
  don't need to commit to get a verdict. Locally that means: all three
  Codeception suites green, zero fixer drift, zero PHPStan level-8 errors,
  **zero deptrac violations** — the architecture law applies to your code
  exactly as it applies to the existing code, and several exercises below
  deliberately walk the edge of it.
- Contract changes (new endpoints, new statuses, changed semantics) update
  `README.md` as part of the exercise — its API tables, state-machine bullets
  and event-seam diagram are documentation *of record*, and stale docs fail
  review.
- When you're done, ask for a review and name the exercise. Reviews check the
  acceptance criteria, the test quality, and whether the change stayed
  consistent with the codebase's conventions: pure domain (no vendor imports
  in Domain/Application), invariants in aggregates, ports in Domain with
  adapters in Infrastructure, DTO + Validator input, the single
  `{"errors":[{"field","message"}]}` shape, and the pinned status rule —
  **422** = the request itself is invalid, **409** = valid request,
  conflicting state.

Paths below are relative to `order-system/`.

---

## 1. A `Money` operation with full VO discipline: `discountedBy()`

**Task.** Add a percentage discount to `src/Shared/Domain/Money.php`:
`Money::of(10000, 'EUR')->discountedBy(25)` is `Money::of(7500, 'EUR')`. The
percentage is an int from 0 to 100; anything else throws. Integer arithmetic
only, with an explicitly chosen and documented rounding rule.

**Why it teaches:** `Money` is the "why value objects" lesson — extending it
means practicing every part of the discipline at once: validation at the
boundary, immutability, exactness, and a *decision* (rounding) that a bare
`float` would have silently made for you.

**Acceptance criteria**

- New method on `Money`, same shape as `add()`: returns a new instance,
  original untouched, currency preserved. Percent outside 0–100 →
  `\InvalidArgumentException` (note that 0 and 100 are both *legal* — one is
  the identity, the other is free; say why you kept them).
- No float touches the computation. The rounding policy for inexact results
  (10% off 1999 minor units is 1799.1) is stated in the docblock: floor
  (merchant keeps the fraction), round-half-up (customer-friendly), or
  ceiling — pick one and defend it in a sentence. An undecided default is
  the one wrong answer.
- Tests in `tests/Unit/Shared/MoneyTest.php`, in that file's style:
  - happy path and both boundaries (0% and 100%);
  - a rejection data provider (`-1`, `101`) modeled on `invalidCurrencies`;
  - an immutability test mirroring `testAddIsImmutable`;
  - a **rounding table**: at least three inexact cases whose expected values
    pin your chosen policy so the next person can't change it silently.
- Honest note for the review: nothing in production calls this yet. That is
  allowed — a VO's contract may precede its consumer (a coupon feature, or
  exercise 5's threshold arithmetic, would be natural callers) — but you must
  say it, not hide it.

<details>
<summary>Hints</summary>

- `intdiv($this->amountMinor * (100 - $percent), 100)` is the floor policy in
  one expression; `intdiv($this->amountMinor * (100 - $percent) + 50, 100)`
  is round-half-up. 64-bit ints make overflow a non-issue at any plausible
  order total.
- The constructor is private; build the result with `new self(...)` exactly
  as `add()` and `multiplyBy()` do.
- Keep the parameter a plain `int`. A `Percentage` VO is defensible but heavy
  for one call site — if you build one anyway, give it its own test file and
  say why it earned existence.
</details>

---

## 2. The missing fourth email: a cancellation notice

**Task.** Notification currently ignores `OrderCancelled` — and the
integration suite *pins that non-subscription* in
`tests/Integration/Notification/NotificationLogCest.php::cancellationSendsNothing`.
The business has changed its mind: cancellations now email the customer, and
the wording must differ when the order had been paid (`hadBeenPaid` — a
refund is coming) versus merely placed.

**Why it teaches:** you consume the published language conformist-style —
composing everything from the event payload alone — and you overturn a pinned
behavior *honestly*, by rewriting the test that asserted the old world
instead of deleting it.

**Acceptance criteria**

- `EmailMessage::TYPE_CANCELLATION_NOTICE = 'cancellation_notice'` added to
  the constant list **and** the constructor's whitelist in
  `src/Notification/Domain/EmailMessage.php`. No migration needed —
  `notification_log.type` is `VARCHAR(32)`; verify that yourself rather than
  trusting this sentence.
- New subscriber `src/Notification/Application/Subscriber/SendCancellationNotice.php`,
  plain PHP, modeled on `SendOrderConfirmation`: constructor takes the
  `Mailer` port, `__invoke(OrderCancelled $event)`, everything in the message
  from the event's scalars. The body branches on `$event->hadBeenPaid`.
- Wired in `config/services.yaml` with a `kernel.event_listener` tag next to
  the other three Notification subscribers (the comment block there explains
  why Application classes are tagged in config instead of using attributes —
  be able to repeat the reason).
- Deptrac: zero edits, still green — and you can point at the line in
  `deptrac.yaml` that already made this legal
  (`NotificationApplication: [..., OrderingEvents]`).
- Tests:
  - Unit, `tests/Unit/Notification/MessageCompositionTest.php`: one test per
    branch (paid / unpaid), using the recording `Mailer` from `_before()`;
    assert type, subject and the branch-specific wording.
  - Integration: `cancellationSendsNothing` is now wrong. **Rewrite it**
    (e.g. `cancellationLogsACancellationNotice`) asserting types
    `['order_confirmation', 'cancellation_notice']`, and add a paid-path
    test (cancel after `PayOrderHandler` + `tok_success`) asserting the
    refund wording and the full type sequence. The old test's comment cites
    the PRD's consumer table — update the citation to say the table changed,
    don't leave a comment arguing with the code.
  - Acceptance: after a cancel, `GET /api/notifications?orderId=` includes
    the new type — extend
    `tests/Acceptance/OrderLifecycleCest.php::cancellingAPlacedOrderReleasesTheStock`
    or add a test to `OrderHistoryAndNotificationsCest`.
- `README.md`: the event-seam diagram's `OrderCancelled` line gains the
  notification consumer.

<details>
<summary>Hints</summary>

- Subject convention from the neighbors: `'Order cancelled — <orderId>'`.
- Notice what the payload *doesn't* carry: `OrderCancelled` has no amounts,
  so the refund branch cannot state a figure. Stay vague ("your payment will
  be refunded") and say why in the review — enriching a published event for
  the sake of prose is a contract change every consumer pays for. Exercise 6
  hits this same wall harder.
- `NotificationLogCest::rowsFor()` orders by `id`, so type sequences are
  deterministic — assert the whole array like `shippingLogsAShipmentNotice`
  does.
</details>

---

## 3. Tighten an invariant test-first: a 200-unit order ceiling

**Task.** New business rule, O6: an order may not contain more than **200
total units** across all lines. `Quantity` caps a *line* at 99 and the cart
caps *lines* at 50, so today a 4,950-unit order is legal. Enforce the ceiling
in the `Order` aggregate — and drive it from a failing unit test written
before any production code.

**Why it teaches:** "aggregates enforce their own invariants" as muscle
memory: red test in the pure domain first, the smallest change to green,
then the HTTP story — plus a deliberate 422-vs-409 call you must defend.

**Acceptance criteria**

- **The failing test comes first** and you state in review that you watched
  it fail. In `tests/Unit/Ordering/OrderTest.php`: 99 + 99 + 3 = 201 units →
  throws; 99 + 99 + 2 = 200 → places fine (the boundary is legal).
- New exception `src/Ordering/Domain/OrderTooLarge.php` extending
  `\DomainException` and **not** implementing `StateConflict` — if it did,
  the shared `JsonExceptionListener` would render 409 before your controller
  catch ever ran. `EmptyCart` is the precedent for a plain domain exception
  mapped to 422; read both before deciding you agree.
- Enforcement lives in `Order`'s private constructor, next to the existing
  O3 checks — so `place()` and any future named constructor are guarded by
  construction, like the currency rule. Constant on the class in the style
  of `REFUND_WINDOW`: `public const int MAX_UNITS = 200;`. The message names
  the cap and the offending count.
- `OrderController::place()` catches it exactly as it catches `EmptyCart` →
  `ApiProblem::validationError(null, ...)` → 422. Defend the status: an
  oversized cart is a deterministic property of what the customer built and
  can see via `GET /api/cart` (like an empty cart, 422) — not a
  world-changed-underneath-you conflict (like insufficient stock, 409).
- Acceptance test in `tests/Acceptance/OrderLifecycleCest.php`: three
  products, stock 99 each, three cart lines of quantity 99 → checkout 422,
  and the cart is *intact* afterwards (mirror the cart-survives assertion in
  `insufficientStockRejectsCheckoutAtomically`).
- The `Cart` is deliberately **not** changed: it may still hold more than
  200 units, and only checkout complains — the same stance the codebase
  already takes for a deactivated product sitting in a cart. Say so; a
  cart-side pre-check is an allowed stretch, but it is additive, never a
  replacement for the aggregate guard.
- One sentence in review on hydration: Doctrine restores persisted orders by
  reflection, not through the constructor — so the new rule constrains new
  placements without threatening any (hypothetical) existing oversized rows.

<details>
<summary>Hints</summary>

- The constructor already loops the lines to build `OrderLine` entities —
  sum `$line->quantity->value` in that same loop; don't add a second pass.
- Unit fixtures: `placedOrder()` uses quantities 2 + 3; build the oversized
  and boundary orders with a small local helper that takes a list of
  quantities and fabricates `NewOrderLine`s (one SKU per line — SKU
  uniqueness across lines is not an Order invariant, and this exercise
  shouldn't invent one).
- The acceptance test needs three *distinct* SKUs because
  `PUT /api/cart/lines/{sku}` replaces a line rather than stacking it.
</details>

---

## 4. Make the law match the comment: carve an `OrderingAcl` deptrac layer

**Task.** `src/Ordering/Infrastructure/Acl/CatalogProductCatalog.php` calls
itself "the single legal cross-context call in the codebase" — but the
ruleset is looser than the comment: `OrderingInfrastructure` as a *whole*
may depend on `CatalogPublicApi`, so a controller could import
`ProductCatalogQuery` tomorrow and deptrac would shrug. Tighten the law:
carve `src/Ordering/Infrastructure/Acl` into its own `OrderingAcl` layer,
grant `CatalogPublicApi` to it alone, then **plant a violation** to prove
the gate bites, and revert the plant.

**Why it teaches:** reading the gap between what a comment claims and what
the ruleset enforces — then closing it — is the entire deptrac skill. The
planted-red step is how you learn to trust (and distrust) your own rules.

**Acceptance criteria**

- `deptrac.yaml`:
  - new layer `OrderingAcl` collecting `src/Ordering/Infrastructure/Acl/.*`;
  - `OrderingInfrastructure` becomes a `bool` collector with a `must_not` on
    the `Acl` directory — the exact pattern `CatalogApplication` already
    uses to exclude `PublicApi/`, and `{Ctx}Domain` uses to exclude
    `Event/`;
  - ruleset: `OrderingAcl: [OrderingDomain, SharedDomain, CatalogPublicApi]`
    — and notice what's *absent*: no `Vendor`. The adapter is pure PHP, and
    now the law documents that too;
  - `OrderingInfrastructure` loses `CatalogPublicApi` from its allowlist.
- `docker compose exec php vendor/bin/deptrac` green on the clean tree.
- **The plant:** give `CartController` a constructor-promoted
  `ProductCatalogQuery` parameter and call `activeBySku()` somewhere
  reachable. Run deptrac: exactly one violation,
  `OrderingInfrastructure -> CatalogPublicApi`. Quote the violation line in
  your review notes. Revert the plant (`git restore` the controller); green
  again; `git status` shows only `deptrac.yaml`, `README.md` and any doc
  files changed.
- `README.md`, "The architecture law" section: the bullet about Ordering's
  infra calling `PublicApi` now names the `Acl` layer as the sole grantee.

<details>
<summary>Hints</summary>

- deptrac counts *real code references* — the README's "See it fail" note
  warns that a bare unused `use` import doesn't trip it. A promoted
  constructor property is a real reference; a call is even more honest.
- If a ruleset edit seems to change nothing, delete `.deptrac.cache` — the
  cache can serve stale analysis across config changes.
- Sanity-check the carve didn't orphan anything: the `Http/`, `Persistence/`,
  `Payment/` and `Doctrine/` subdirectories must still land in
  `OrderingInfrastructure` (run deptrac with `--report-uncovered` if you
  want proof rather than faith).
- For the full experience, run *Build Now* once with the plant in place and
  watch stage 5, "Deptrac (architecture gate)", go red before you revert.
</details>

---

## 5. Swap the payment adapter: `ManualReviewPaymentGateway`

**Task.** Fraud ops decree: any charge of **100 000 minor units or more**
(EUR 1000.00) is auto-declined with reason `manual_review_required` —
regardless of token. Implement it without touching a single Domain or
Application file: a new Infrastructure adapter that wraps the existing
`FakePaymentGateway` and short-circuits above the threshold.

**Why it teaches:** the spec's hexagonal claim — "swapping the payment
adapter without touching the domain" — stops being a README sentence and
becomes your own `git diff --stat`.

**Acceptance criteria**

- New class `src/Ordering/Infrastructure/Payment/ManualReviewPaymentGateway.php`
  implementing `PaymentGateway`, holding an inner `PaymentGateway`:
  at-or-above threshold → `PaymentResult::declined('manual_review_required')`
  *without* calling the inner adapter; below → delegate untouched. Threshold
  as a class constant.
- `config/services.yaml`: the port binding
  (`App\Ordering\Domain\Port\PaymentGateway:`) now points at the new class,
  plus an explicit service block wiring `$inner` to `FakePaymentGateway`.
  Autowiring alone cannot choose between two implementations of one
  interface — trigger that error once on purpose so you've seen it, then fix
  it.
- **The proof, quoted in review notes:** `git diff --stat` shows `src/`
  changes only under `Ordering/Infrastructure/Payment/`, plus `config/`,
  `tests/`, `README.md`. Zero Domain, zero Application.
- A stated decision: a manual-review decline writes **no**
  `ordering_payment_attempt` row (that ledger is the fake's private property,
  and the charge never reached the "processor") — defend that, or wire it
  differently and defend *that*.
- Tests:
  - Integration, new `tests/Integration/Ordering/ManualReviewPaymentGatewayCest.php`
    grabbing the gateway **through the port** like `FakePaymentGatewayCest`
    does: at-threshold declines with the reason even for `tok_success`
    (and leaves no attempt row, if that's your decision); one cent below
    delegates — `tok_success` approves and the attempt row exists,
    `tok_declined` still says `insufficient_funds`.
  - Acceptance: product with `priceMinor` 100000, quantity 1, checkout, pay
    with `tok_success` → 422 `"Payment was declined."` and the order is
    still placed-and-payable (mirror `declinedPaymentLeavesTheOrderPayable`).
  - The existing suites stay green *unmodified*: every current test's total
    sits far below the threshold (the priciest seeded product anywhere is
    2500 minor), and `FakePaymentGatewayCest` charges 1000 — it now
    exercises your decorated chain and keeps passing, which is itself
    evidence the delegation is faithful.
- `README.md`: the fake-gateway token paragraph gains the threshold rule.

<details>
<summary>Hints</summary>

- The domain already speaks "declined with a reason": `PaymentResult` carries
  the reason, `PayOrderHandler` converts any decline into
  `PaymentWasDeclined` (message always `"Payment was declined."`, reason
  held separately) → 422. So the *acceptance* test asserts the message while
  the *integration* test asserts the reason — the split is deliberate;
  notice it.
- Two wiring styles both pass review: an explicit `arguments:
  { $inner: '@App\Ordering\Infrastructure\Payment\FakePaymentGateway' }`
  block, or Symfony's `decorates:`. For a single decorator the explicit
  block is the more legible; whichever you choose, one sentence of
  justification.
- Deptrac needs no edit — the new class lives in `OrderingInfrastructure`
  and imports only `OrderingDomain` ports. Run it anyway; belief is not a
  verification step.
- Compare with part 2's exercise 6 (the logging decorator) if you did it:
  same pattern, different seam — there it wrapped a query provider, here it
  wraps a *port*, which is why the domain cannot tell the difference.
</details>

---

## 6. A new read model + projector: `read_sku_demand`

**Task.** Merchandising wants to know which SKUs actually get ordered. Build
a CQRS-lite read model `read_sku_demand` (sku, units_ordered, orders_count,
last_ordered_at) projected from `OrderPlaced`, and serve it at
⚙ `GET /api/demand?limit=` (admin-ish: no customer header) — top SKUs by
units, `limit` 1–100 defaulting to 10.

**Why it teaches:** you assemble the whole projection triad — Application
port, projector, DBAL adapter, migration, wiring — once, yourself. And you
hit the classic projector wall: the event you'd need next doesn't carry the
data you want.

**Acceptance criteria**

- Migration in `migrations/`, hand-written in the style of
  `Version20260719120000.php` (which also shows the backfill pattern —
  yours backfills from `ordering_order_line` grouped by SKU). Applied to
  **both** databases before the integration suite runs — the entrypoint does
  both on `docker compose restart php`, or run `doctrine:migrations:migrate`
  twice, plain and `--env=test`.
- Port `src/Ordering/Application/Projection/SkuDemandReadModel.php` —
  interface next to its projector, vendor-free, upsert-shaped like
  `ProductListReadModel` (the two existing read-model ports show the two
  house shapes; pick the fit and say why).
- Projector `SkuDemandProjector` in the same directory, consuming
  **`OrderPlaced` only**, everything from the payload's `lines` array —
  `OrderSummaryProjector` (counts the lines) and `SendOrderConfirmation`
  (maps them) show the payload's exact shape.
- Adapter `src/Ordering/Infrastructure/Persistence/DbalSkuDemandReadModel.php`
  (`INSERT ... ON DUPLICATE KEY UPDATE` — crib from
  `DbalProductListReadModel`), read-side query class shaped like
  `OrderHistoryQuery`, and a small controller in
  `Ordering/Infrastructure/Http` with `limit` validated → 422 naming
  `limit` on anything off-contract.
- `config/services.yaml`: the read-model port binding beside the other two,
  and one `kernel.event_listener` tag for the projector.
- **The wall, written down.** Cancelled orders still count — `OrderCancelled`
  carries no lines, so your projector *cannot* decrement. Inventory dodges
  this same gap by keeping its own `Reservation` aggregate and reading it
  back on cancellation (`ReleaseStockOnOrderCancelled`). Ship **gross**
  demand, name it honestly, and write the decision paragraph (class comment
  or README): what net demand would cost — enriching the published
  `OrderCancelled` payload (a contract change every consumer sees) versus
  projecting a compensating read from your own write model. This paragraph
  is a review focus, equal in weight to the code.
- Deptrac: zero edits — and you can say why (the projector consumes its
  *own* context's events, already granted to `OrderingApplication`; the
  adapter is `OrderingInfrastructure → Vendor`, also already granted).
- Tests:
  - Integration, new `tests/Integration/Ordering/SkuDemandProjectionCest.php`
    modeled on `OrderSummaryProjectionCest`: two orders for the same SKU
    aggregate units and count; a rolled-back checkout
    (`InsufficientStock`) leaves no row — the same-transaction guarantee,
    exactly as `aRolledBackCheckoutProjectsNoSummaryRow` pins it; and a
    test whose *name* admits the semantics
    (`cancellationDoesNotReduceDemandByDesign` or similar).
  - Acceptance: seed → order → `GET /api/demand` shows the SKU with its
    units; `limit=0` → 422.
- `README.md`: the CQRS section gains the third read model — including an
  honest sentence about where it sits on the earns-its-keep spectrum
  (it composes only *one* context, so it earns less than
  `read_product_list`; the PRD's ceremony note applies).

<details>
<summary>Hints</summary>

- Money deliberately stays out of the response (units, not revenue). If you
  add `revenue_minor` anyway, it renders as
  `{"amountMinor": int, "currency": ...}` like every other money in the API
  — but then *currency* becomes a column and a mixed-currency SKU becomes
  your problem; the smaller scope is the wiser scope.
- `last_ordered_at` comes from the event's `placedAt`, not the clock — the
  projector has no business asking the time when the fact carries it.
- The listener tag needs no `method:` key if the projector is a single
  `__invoke(OrderPlaced $event)` — the Inventory subscribers show that
  variant; `OrderSummaryProjector` shows the multi-method variant. One
  event, one method: `__invoke` fits.
</details>

---

## 7. A new transition, entirely in the aggregate: refund-after-ship

**Task.** `shipped` stops being terminal: a customer may refund a shipped
order within **14 days of shipment**. New status `refunded`, new transition
`shipped → refunded` guarded by its own window (`P14D`), new domain event
`OrderRefunded`, endpoint `POST /api/orders/{id}/refund` (customer-scoped).
The rule lives in the `Order` aggregate and nowhere else.

**Why it teaches:** the full state-machine vertical — enum case, aggregate
method, window arithmetic, event, projector, handler, route — each layer
done exactly the way the four existing transitions do it. If you find
yourself inventing, you've stopped reading.

**Acceptance criteria**

- `OrderStatus::Refunded = 'refunded'` — first verify it fits the `status`
  columns (`ordering_order` and `read_order_summary` are both
  `VARCHAR(10)`); the enum-type XML mapping needs no other change.
- `Order`: `public const string RETURN_WINDOW = 'P14D';` and
  `refund(\DateTimeImmutable $now)` — legal only from `Shipped` and only
  while `now < shippedAt + 14d`; **at** the boundary the window is shut,
  same convention as `cancel()`, and a unit test pins the exact second. Sets
  a new `refundedAt`, records `OrderRefunded`. `refunded` is terminal:
  `pay`/`ship`/`cancel`/`refund` all throw on it.
- A window-shut exception of its own (e.g. `ReturnWindowClosed`) —
  `RefundWindowClosed`'s docblock explains why a shut window is *not* an
  `IllegalOrderTransition`; the same reasoning names yours. It implements
  `StateConflict` → 409 via the shared listener, **no controller catch** —
  and you can articulate why this is 409 where exercise 3's ceiling was 422
  (time moved the world; the customer's request was well-formed).
- New event `src/Ordering/Domain/Event/OrderRefunded.php`: orderId,
  customerId, refundedAt — past tense, scalars, the house style of the four
  neighbors.
- Migration: `ordering_order.refunded_at DATETIME NULL`; the field added to
  `config/doctrine/Ordering/Order.orm.xml`; `orderJson()` in
  `OrderController` gains `refundedAt`.
- Application + HTTP: `RefundOrder` + `RefundOrderHandler` mirroring
  `CancelOrderHandler` line for line (locked `byIdForUpdate` re-read,
  ownership check → `OrderNotFound` → 404 existence-hiding, save, dispatch);
  route with the UUID requirement like its siblings.
- `OrderSummaryProjector::onOrderRefunded` + its `services.yaml` tag —
  without it `GET /api/orders` silently lies about status forever, which is
  precisely the drift the PRD's "every mutation publishes" rule exists to
  prevent. Say that in the review.
- **Inventory decision, stated in the handler or aggregate comment:** no
  stock movement on refund. The stock was committed out of the warehouse at
  shipment; physical returns are an unbuilt RMA story (the same out-of-scope
  honesty the PRD practices). A reviewer will ask; the comment answers first.
- Notification: optional stretch — if you did exercise 2, a refund notice is
  ten minutes of the same pattern.
- Tests:
  - Unit, `OrderTest`: refund from `placed`/`paid`/`cancelled` throws
    `IllegalOrderTransition`; from `shipped` inside the window → status,
    `refundedAt`, and the exact `OrderRefunded` payload; at
    `shippedAt + P14D` exactly → rejected, order untouched; well past →
    rejected. Extend the terminal-immutability coverage: `refund` joins the
    mutation loop in `testACancelledOrderRejectsEveryMutation`, and a
    refunded twin of that test proves the new terminal state. The
    `shippedOrder()` fixture already exists.
  - Integration: `OrderSummaryProjectionCest` follows the status through
    refund (extend `statusFollowsPayShipAndCancel` or add a sibling).
  - Acceptance, `OrderLifecycleCest`: place → pay → ship → refund 200 with
    `status: refunded` and `refundedAt` set; refund a placed order → 409;
    double refund → 409; history shows `refunded`. The 14-day *expiry* is
    unit-only — HTTP can't time-travel, as the suite already says about the
    24-hour window.
- `README.md`: state-machine bullet, API table row, event-seam line.

<details>
<summary>Hints</summary>

- `cancel()` is the template for the window arithmetic
  (`\DateInterval` + `>=` for "shut at the boundary"); `ship()` for the
  simple status guard. The `assert($this->shippedAt !== null)` mirrors the
  `paidAt` one and keeps PHPStan level 8 quiet without a nullable dance.
- The handler really is `CancelOrderHandler` with the nouns changed. Resist
  improving it; symmetry is the feature.
- The event has one consumer (the projector). That's enough — contrast with
  `Cart`, which emits nothing *because* nothing listens. One honest
  sentence about that asymmetry earns review points.
- Refund vs. the paid-cancel path: `cancel()` on a paid order releases
  reserved stock via Inventory's subscriber. Your refund must *not* release
  anything — the reservation was already committed at ship
  (`CommitStockOnOrderShipped`, R2 makes a double-finalize throw). If you
  wire `OrderRefunded` into Inventory by reflex, the reservation guard will
  teach you why you shouldn't have.
</details>

---

## 8. Capstone — a second ACL: stock only for catalogued SKUs

**Task.** Warehouse ops keep fat-fingering SKUs into `PUT /api/stock/{sku}`.
New rule: stock may only be set for SKUs the catalog knows — **active or
not**. Today the *opposite* is a documented, tested design decision: the
class comment on `SetStockLevelHandler` argues there must be no
"product must exist" rule, `StockApiCest`'s header repeats it, the
`ProductListReadModel` port comment leans on it, and
`ProductListProjectionCest::stockBeforeProductSurvivesInAHiddenRowUntilTheProductIsAdded`
exercises it end to end. Implement the new rule through an anti-corruption
port — and pay the full price of overturning a documented decision.

**Why it teaches:** the Ordering→Catalog ACL is the codebase's showpiece.
Building the *second* one yourself — growing Catalog's published API,
amending the deptrac law, and hunting down every place the old decision put
down roots — is the test of whether the pattern actually transferred. The
archaeology is half the exercise.

**Acceptance criteria**

- Port in Inventory's Domain, Inventory's language:
  `src/Inventory/Domain/Port/ProductDirectory.php` with something like
  `knowsSku(Sku $sku): bool` — placed and documented the way
  `Ordering/Domain/Port/ProductCatalog.php` is.
- Catalog's published API grows a second method on `ProductCatalogQuery`
  (e.g. `existsBySku(Sku): bool`) that **ignores `active`** — deactivated
  products still occupy shelf space. State why the existing `activeBySku()`
  is the wrong contract for a warehouse: P3 ("inactive products are
  unsellable") is a *selling* rule, and a stocking check that enforced it
  would smuggle Catalog's sales policy into Inventory. Keep the return type
  the narrowest possible (`bool`, not `ProductSummary`) — the README's
  bounded-context table says the warehouse must not know names or prices,
  and your API shape should make that unrepresentable.
- Adapter `src/Inventory/Infrastructure/Acl/CatalogProductDirectory.php`
  calling only the PublicApi (template: `CatalogProductCatalog`, ~15 lines);
  port→adapter binding in `config/services.yaml` beside the others.
- `deptrac.yaml`: `CatalogPublicApi` granted to Inventory's infrastructure —
  as a carved `InventoryAcl` layer if you did exercise 4 (consistency
  between the two carve-outs is a review topic), or on
  `InventoryInfrastructure` if not. Either way the ruleset comment that
  called the Ordering adapter "the one legal cross-context call" is now
  wrong and must be rewritten, in `deptrac.yaml` *and* in
  `CatalogProductCatalog`'s docblock.
- `SetStockLevelHandler` consults the port before upserting; unknown SKU →
  new Inventory Domain exception (`UnknownCatalogProduct`, plain
  `\DomainException` — the cart's `UnknownOrInactiveProduct` → 422 is the
  precedent); `StockController::set()` catches → 422 on field `sku`. The
  handler's class comment is **rewritten** — it currently argues the rule
  "must not be", and a comment arguing against the code below it is worse
  than no comment.
- **The blast radius, enumerated.** Run all three suites, let red guide you,
  then reconcile against this list — anything you found that isn't here, or
  vice versa, goes in your review notes:
  - `StockApiCest`: `putCreatesAndUpdatesAStockLevel` and
    `zeroOnHandIsValid` must now seed products first; the header comment
    updates; a new test pins the 422 for an uncatalogued SKU; and a new
    test proves stock for a *deactivated* product still works (that's the
    `existsBySku`-ignores-`active` contract, observable over HTTP).
  - `negativeOnHandIs422`, `missingOnHandIs422`, `nonIntegerOnHandIs400`
    survive untouched — and you can say why: DTO validation runs before the
    handler ever sees the request, so the catalog is never consulted.
  - `ProductListProjectionCest::stockBeforeProductSurvivesInAHiddenRowUntilTheProductIsAdded`:
    its premise — stock before product, over the API — is now impossible.
    Decide: keep `upsertAvailability`'s hidden-row mechanism as
    defense-in-depth but retire/rewrite the test and soften the
    `ProductListReadModel` port comment; or strip the mechanism too (purer,
    bigger diff). Either choice defended is fine. **Not noticing this test
    is the failure mode this exercise exists to teach.**
  - Integration cests that seed properly (`NotificationLogCest::seed` runs
    `AddProduct` before `SetStockLevel`) keep passing — which tells you the
    choreography every test now needs.
- A stated contract nuance: `GET /api/stock/{sku}` stays permissive. New
  uncatalogued rows can no longer be created, but a real system could hold
  legacy rows, and a read that 404s data you possess helps nobody.
- Tests to add beyond the reworked ones: integration coverage of the handler
  path (unknown SKU throws `UnknownCatalogProduct`; known-but-deactivated
  succeeds), plus one sentence on why there is **no new unit test in
  Inventory's Domain**: the rule is relational across contexts, so
  `StockItem` cannot enforce it — the Application handler consulting a
  Domain port is the right home, exactly as `PlaceOrderHandler` +
  `ProductCatalog` + `ProductNoLongerAvailable` already demonstrate.
- `README.md`: the stock endpoint row gains the 422; every sentence claiming
  "no product-must-exist coupling" (README, PRD quotes in comments) is
  hunted down and updated. Stale docs fail review — in this exercise, that
  rule is most of the work.

<details>
<summary>Hints</summary>

- Grep is your archaeologist: search the tree for `never heard`,
  `must not be`, `product_known`, and `hidden row` before you write any
  code. Build the blast-radius list first; implement second. It inverts the
  usual order and it's faster.
- The bounded-context nuance worth writing down: after this change Inventory
  still knows nothing about price, name, or active-for-sale — it knows one
  new *fact*, "the catalog has heard of this SKU". The contexts' models stay
  disjoint; only the handshake grew. If your port returns more than a bool,
  re-read the README's context table and shrink it.
- `existsBySku` wants `ProductRepository::bySkuOrNull(...) !== null` — no
  new repository method needed. Resist adding one.
- Ordering's checkout path is untouched: it already refuses unknown/inactive
  products via its own ACL. If you find yourself editing anything under
  `src/Ordering/`, stop and re-read the task.
- The upsert-vs-guard order inside the handler matters for the lock: check
  the catalog *before* taking the `bySkuForUpdate` row lock — no point
  serializing on a row you're about to refuse to touch. One comment line on
  that ordering shows a reviewer you saw it.
</details>

---

## When you finish

Each exercise ends the same way: all three suites green inside the
container, fixer clean, PHPStan level-8 clean, **deptrac clean**, *Build
Now* green on 8084, README honest. Then ask for the review, naming the
exercise number and flagging any place you deviated from the acceptance
criteria on purpose — a justified deviation is a conversation; a silent one
is a finding.
