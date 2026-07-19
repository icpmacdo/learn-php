<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderShipped;
use App\Ordering\Domain\IllegalOrderTransition;
use App\Ordering\Domain\NewOrderLine;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Ordering\Domain\RefundWindowClosed;
use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Shared\Domain\UuidV7;
use Codeception\Test\Unit;

/**
 * The full transition table of the Order state machine (O1), the computed
 * total (O2), line rules (O3), immutability after terminal states (O4),
 * throwing pay-on-paid (O5) — and the refund-window edge to the second,
 * which only a unit test with a hand-picked "now" can reach (acceptance
 * tests cannot time-travel; said honestly in the PRD).
 */
final class OrderTest extends Unit
{
    private const string NOW = '2026-07-18T12:00:00+00:00';

    // ---------------------------------------------------------------- place

    public function testPlaceCreatesAPlacedOrderWithMintedUuidV7Identity(): void
    {
        $order = $this->placedOrder();

        $this->assertSame(OrderStatus::Placed, $order->status());
        $this->assertTrue(UuidV7::isWellFormed($order->id()->value));
        $this->assertSame(self::NOW, $order->placedAt()->format(\DateTimeInterface::ATOM));
        $this->assertNull($order->paidAt());
        $this->assertNull($order->transactionId());
    }

    public function testTotalIsComputedFromLines(): void
    {
        // O2: 2 x 1999 + 3 x 500 = 5498, never stored, always derived.
        $order = $this->placedOrder();

        $this->assertTrue($order->total()->equals(Money::of(5498, 'EUR')));
        $this->assertSame('EUR', $order->currency());
        $this->assertCount(2, $order->lines());
    }

    public function testLineTotalsMultiplyOut(): void
    {
        $lines = $this->placedOrder()->lines();

        $this->assertTrue($lines[0]->lineTotal()->equals(Money::of(3998, 'EUR')));
        $this->assertTrue($lines[1]->lineTotal()->equals(Money::of(1500, 'EUR')));
    }

    public function testPlaceRecordsOrderPlacedWithExactPayload(): void
    {
        $order = $this->placedOrder();
        $events = $order->releaseEvents();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(OrderPlaced::class, $event);
        $this->assertSame($order->id()->value, $event->orderId);
        $this->assertSame('cust-1', $event->customerId);
        $this->assertSame(5498, $event->totalMinor);
        $this->assertSame('EUR', $event->currency);
        $this->assertSame(self::NOW, $event->placedAt->format(\DateTimeInterface::ATOM));
        $this->assertSame([
            ['sku' => 'WIDGET-1', 'name' => 'Widget', 'quantity' => 2, 'unitPriceMinor' => 1999, 'currency' => 'EUR'],
            ['sku' => 'GADGET-1', 'name' => 'Gadget', 'quantity' => 3, 'unitPriceMinor' => 500, 'currency' => 'EUR'],
        ], $event->lines);
    }

    public function testReleaseEventsDrainsTheBuffer(): void
    {
        $order = $this->placedOrder();
        $order->releaseEvents();

        $this->assertSame([], $order->releaseEvents());
    }

    public function testPlaceRejectsZeroLines(): void
    {
        $this->expectException(\DomainException::class); // O3
        Order::place(CustomerId::fromString('cust-1'), [], $this->at(self::NOW));
    }

    public function testPlaceRejectsMixedCurrencies(): void
    {
        $this->expectException(CurrencyMismatch::class); // O3
        Order::place(CustomerId::fromString('cust-1'), [
            new NewOrderLine(Sku::fromString('WIDGET-1'), 'Widget', Money::of(1999, 'EUR'), Quantity::of(1)),
            new NewOrderLine(Sku::fromString('GADGET-1'), 'Gadget', Money::of(500, 'USD'), Quantity::of(1)),
        ], $this->at(self::NOW));
    }

    // ------------------------------------------------------------------ pay

    public function testPayMovesPlacedToPaidAndRecordsOrderPaid(): void
    {
        $order = $this->placedOrder();
        $order->releaseEvents();

        $order->pay('fake_tx-1', $this->at('2026-07-18T13:00:00+00:00'));

        $this->assertSame(OrderStatus::Paid, $order->status());
        $this->assertSame('fake_tx-1', $order->transactionId());
        $this->assertSame('2026-07-18T13:00:00+00:00', $order->paidAt()?->format(\DateTimeInterface::ATOM));

        $events = $order->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(OrderPaid::class, $event);
        $this->assertSame($order->id()->value, $event->orderId);
        $this->assertSame('fake_tx-1', $event->transactionId);
        $this->assertSame(5498, $event->totalMinor);
    }

    public function testPayingAPaidOrderThrows(): void
    {
        // O5: pay() is idempotent-hostile by design — retry safety lives at
        // the gateway seam, not in a forgiving aggregate.
        $order = $this->paidOrder();

        $this->expectException(IllegalOrderTransition::class);
        $order->pay('fake_tx-2', $this->at('2026-07-18T14:00:00+00:00'));
    }

    public function testPayingAShippedOrderThrows(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(IllegalOrderTransition::class);
        $order->pay('fake_tx-2', $this->at('2026-07-18T15:00:00+00:00'));
    }

    public function testPayingACancelledOrderThrows(): void
    {
        $order = $this->placedOrder();
        $order->cancel($this->at('2026-07-18T12:30:00+00:00'));

        $this->expectException(IllegalOrderTransition::class);
        $order->pay('fake_tx-2', $this->at('2026-07-18T13:00:00+00:00'));
    }

    public function testAssertPayableThrowsForNonPlacedWithoutMutating(): void
    {
        $order = $this->paidOrder();

        try {
            $order->assertPayable();
            $this->fail('Expected IllegalOrderTransition.');
        } catch (IllegalOrderTransition $e) {
            $this->assertSame('Cannot pay an order that is paid.', $e->getMessage());
        }
        $this->assertSame(OrderStatus::Paid, $order->status());
    }

    // ----------------------------------------------------------------- ship

    public function testShipMovesPaidToShippedAndRecordsOrderShipped(): void
    {
        $order = $this->paidOrder();
        $order->releaseEvents();

        $order->ship($this->at('2026-07-18T16:00:00+00:00'));

        $this->assertSame(OrderStatus::Shipped, $order->status());
        $this->assertSame('2026-07-18T16:00:00+00:00', $order->shippedAt()?->format(\DateTimeInterface::ATOM));

        $events = $order->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderShipped::class, $events[0]);
    }

    public function testShippingAPlacedOrderThrows(): void
    {
        $order = $this->placedOrder();

        $this->expectException(IllegalOrderTransition::class);
        $order->ship($this->at('2026-07-18T16:00:00+00:00'));
    }

    public function testShippingAShippedOrderThrows(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(IllegalOrderTransition::class);
        $order->ship($this->at('2026-07-18T17:00:00+00:00'));
    }

    public function testShippingACancelledOrderThrows(): void
    {
        $order = $this->placedOrder();
        $order->cancel($this->at('2026-07-18T12:30:00+00:00'));

        $this->expectException(IllegalOrderTransition::class);
        $order->ship($this->at('2026-07-18T16:00:00+00:00'));
    }

    // --------------------------------------------------------------- cancel

    public function testCancellingAPlacedOrderIsFree(): void
    {
        $order = $this->placedOrder();
        $order->releaseEvents();

        $order->cancel($this->at('2026-07-19T12:00:00+00:00')); // any time at all

        $this->assertSame(OrderStatus::Cancelled, $order->status());
        $events = $order->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(OrderCancelled::class, $event);
        $this->assertFalse($event->hadBeenPaid);
    }

    public function testCancellingAPaidOrderInsideTheRefundWindowWorks(): void
    {
        // Paid at 13:00 on the 18th; window closes 13:00 on the 19th.
        $order = $this->paidOrder(); // paidAt = 2026-07-18T13:00:00
        $order->releaseEvents();

        $order->cancel($this->at('2026-07-19T12:59:59+00:00')); // 1s inside

        $this->assertSame(OrderStatus::Cancelled, $order->status());
        $event = $order->releaseEvents()[0];
        $this->assertInstanceOf(OrderCancelled::class, $event);
        $this->assertTrue($event->hadBeenPaid);
    }

    public function testCancellingExactlyAtTheWindowBoundaryIsRejected(): void
    {
        // The rule is now < paidAt + 24h: AT the boundary the window is shut.
        $order = $this->paidOrder(); // paidAt = 2026-07-18T13:00:00

        try {
            $order->cancel($this->at('2026-07-19T13:00:00+00:00'));
            $this->fail('Expected RefundWindowClosed.');
        } catch (RefundWindowClosed $e) {
            $this->assertSame('Refund window has closed.', $e->getMessage());
        }
        $this->assertSame(OrderStatus::Paid, $order->status()); // untouched
    }

    public function testCancellingWellAfterTheWindowIsRejected(): void
    {
        $order = $this->paidOrder();

        $this->expectException(RefundWindowClosed::class);
        $order->cancel($this->at('2026-08-01T00:00:00+00:00'));
    }

    public function testCancellingAShippedOrderThrows(): void
    {
        $order = $this->shippedOrder();

        $this->expectException(IllegalOrderTransition::class);
        $order->cancel($this->at('2026-07-18T17:00:00+00:00'));
    }

    public function testCancellingACancelledOrderThrows(): void
    {
        $order = $this->placedOrder();
        $order->cancel($this->at('2026-07-18T12:30:00+00:00'));

        $this->expectException(IllegalOrderTransition::class);
        $order->cancel($this->at('2026-07-18T12:31:00+00:00'));
    }

    // ---------------------------------------------------- terminal immutability

    public function testACancelledOrderRejectsEveryMutation(): void
    {
        $order = $this->placedOrder();
        $order->cancel($this->at('2026-07-18T12:30:00+00:00'));
        $now = $this->at('2026-07-18T13:00:00+00:00');

        foreach (['pay', 'ship', 'cancel'] as $mutation) {
            try {
                match ($mutation) {
                    'pay' => $order->pay('fake_tx-x', $now),
                    'ship' => $order->ship($now),
                    'cancel' => $order->cancel($now),
                };
                $this->fail(sprintf('Expected %s() on a cancelled order to throw.', $mutation));
            } catch (IllegalOrderTransition) {
                // expected
            }
        }
        $this->assertSame(OrderStatus::Cancelled, $order->status());
        $this->assertTrue($order->total()->equals(Money::of(5498, 'EUR'))); // lines untouched
    }

    // ------------------------------------------------------------- fixtures

    private function placedOrder(): Order
    {
        return Order::place(CustomerId::fromString('cust-1'), [
            new NewOrderLine(Sku::fromString('WIDGET-1'), 'Widget', Money::of(1999, 'EUR'), Quantity::of(2)),
            new NewOrderLine(Sku::fromString('GADGET-1'), 'Gadget', Money::of(500, 'EUR'), Quantity::of(3)),
        ], $this->at(self::NOW));
    }

    private function paidOrder(): Order
    {
        $order = $this->placedOrder();
        $order->pay('fake_tx-1', $this->at('2026-07-18T13:00:00+00:00'));

        return $order;
    }

    private function shippedOrder(): Order
    {
        $order = $this->paidOrder();
        $order->ship($this->at('2026-07-18T14:00:00+00:00'));

        return $order;
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso);
    }
}
