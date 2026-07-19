<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderShipped;
use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Money;
use App\Shared\Domain\RecordsEvents;

/**
 * The Order aggregate — the heart of part 3. Contractual truth at the moment
 * of sale: its lines snapshot name and unit price as they were at placement,
 * so a later catalog price change never rewrites a past order.
 *
 * Invariants (PRD O1-O5), all enforced HERE and only here:
 *   O1  the only legal transitions are placed->paid, placed->cancelled,
 *       paid->shipped, and paid->cancelled iff now < paidAt + 24h (refund
 *       window). shipped and cancelled are terminal. Anything else throws
 *       IllegalOrderTransition (-> 409).
 *   O2  total always equals the sum of line totals — by construction: it is
 *       COMPUTED from the immutable lines, never stored independently, so it
 *       cannot drift.
 *   O3  an order has >= 1 line and all lines share one currency.
 *   O4  nothing outside mutates it: state changes only through place(),
 *       pay(), ship(), cancel(). There are no setters; lines are reachable
 *       but immutable.
 *   O5  pay() on a paid order THROWS (409) — retry safety lives at the
 *       gateway seam (persisted attempts), not in a forgiving pay().
 *
 * The `$now` each mutator receives comes from the Clock port via the
 * Application handler — which is what makes the refund-window edge exactly
 * testable (unit tests hand in any instant they like).
 *
 * Pure PHP: mapping lives in config/doctrine/Ordering/*.orm.xml.
 */
class Order
{
    use RecordsEvents;

    public const string REFUND_WINDOW = 'PT24H';

    private OrderId $id;
    private CustomerId $customerId;
    private OrderStatus $status;
    private string $currency;
    /** @var iterable<int, OrderLine> */
    private iterable $lines;
    private \DateTimeImmutable $placedAt;
    private ?\DateTimeImmutable $paidAt = null;
    private ?\DateTimeImmutable $shippedAt = null;
    private ?\DateTimeImmutable $cancelledAt = null;
    private ?string $transactionId = null;

    /** @param list<NewOrderLine> $lines */
    private function __construct(OrderId $id, CustomerId $customerId, array $lines, \DateTimeImmutable $now)
    {
        if ($lines === []) {
            throw new \DomainException('An order must have at least one line.'); // O3
        }

        $currency = $lines[0]->unitPrice->currency;
        $lineEntities = [];
        foreach ($lines as $line) {
            if ($line->unitPrice->currency !== $currency) {
                throw new CurrencyMismatch('All order lines must share one currency.'); // O3
            }
            $lineEntities[] = new OrderLine($this, $line->sku, $line->name, $line->unitPrice, $line->quantity);
        }

        $this->id = $id;
        $this->customerId = $customerId;
        $this->status = OrderStatus::Placed;
        $this->currency = $currency;
        $this->lines = $lineEntities;
        $this->placedAt = $now;
    }

    /**
     * Checkout: the ONLY way an Order comes into being — born `placed`, with
     * its identity minted here in the domain so the OrderPlaced payload can
     * carry it before any persistence happens.
     *
     * @param list<NewOrderLine> $lines
     */
    public static function place(CustomerId $customerId, array $lines, \DateTimeImmutable $now): self
    {
        $order = new self(OrderId::generate(), $customerId, $lines, $now);
        $order->recordThat(new OrderPlaced(
            $order->id->value,
            $customerId->value,
            array_map(
                static fn (OrderLine $line): array => [
                    'sku' => $line->sku()->value,
                    'name' => $line->name(),
                    'quantity' => $line->quantity()->value,
                    'unitPriceMinor' => $line->unitPrice()->amountMinor,
                    'currency' => $line->unitPrice()->currency,
                ],
                $order->lines(),
            ),
            $order->total()->amountMinor,
            $order->currency,
            $now,
        ));

        return $order;
    }

    /**
     * Cheap pre-flight for the payment flow: is this order in a state where
     * charging the gateway even makes sense? Called BEFORE the external
     * charge so a shipped/cancelled/paid order is never charged; pay() then
     * re-asserts the same rule when the money actually moves.
     *
     * @throws IllegalOrderTransition
     */
    public function assertPayable(): void
    {
        if ($this->status !== OrderStatus::Placed) {
            throw IllegalOrderTransition::attempted('pay', $this->status);
        }
    }

    /** @throws IllegalOrderTransition when not `placed` (O1, O5) */
    public function pay(string $transactionId, \DateTimeImmutable $now): void
    {
        $this->assertPayable();

        $this->status = OrderStatus::Paid;
        $this->paidAt = $now;
        $this->transactionId = $transactionId;
        $this->recordThat(new OrderPaid(
            $this->id->value,
            $this->customerId->value,
            $transactionId,
            $this->total()->amountMinor,
            $this->currency,
            $now,
        ));
    }

    /** @throws IllegalOrderTransition when not `paid` (O1) */
    public function ship(\DateTimeImmutable $now): void
    {
        if ($this->status !== OrderStatus::Paid) {
            throw IllegalOrderTransition::attempted('ship', $this->status);
        }

        $this->status = OrderStatus::Shipped;
        $this->shippedAt = $now;
        $this->recordThat(new OrderShipped($this->id->value, $this->customerId->value, $now));
    }

    /**
     * Free while `placed`; while `paid` only inside the refund window
     * (now < paidAt + 24h — AT the boundary the window is already closed).
     *
     * @throws RefundWindowClosed     when paid and the window has passed (O1)
     * @throws IllegalOrderTransition when shipped or already cancelled (O1)
     */
    public function cancel(\DateTimeImmutable $now): void
    {
        if ($this->status === OrderStatus::Paid) {
            \assert($this->paidAt !== null);
            if ($now >= $this->paidAt->add(new \DateInterval(self::REFUND_WINDOW))) {
                throw new RefundWindowClosed('Refund window has closed.');
            }
        } elseif ($this->status !== OrderStatus::Placed) {
            throw IllegalOrderTransition::attempted('cancel', $this->status);
        }

        $hadBeenPaid = $this->status === OrderStatus::Paid;
        $this->status = OrderStatus::Cancelled;
        $this->cancelledAt = $now;
        $this->recordThat(new OrderCancelled($this->id->value, $this->customerId->value, $hadBeenPaid, $now));
    }

    /** O2: computed from the lines every time — a stored total could drift, this cannot. */
    public function total(): Money
    {
        $total = null;
        foreach ($this->lines as $line) {
            $total = $total === null ? $line->lineTotal() : $total->add($line->lineTotal());
        }
        \assert($total !== null); // O3: an order always has lines

        return $total;
    }

    public function id(): OrderId
    {
        return $this->id;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function status(): OrderStatus
    {
        return $this->status;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /** @return list<OrderLine> */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    public function placedAt(): \DateTimeImmutable
    {
        return $this->placedAt;
    }

    public function paidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function shippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function cancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }
}
