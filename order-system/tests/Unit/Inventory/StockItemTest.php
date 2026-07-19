<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\Event\StockCommitted;
use App\Inventory\Domain\Event\StockReceived;
use App\Inventory\Domain\Event\StockReleased;
use App\Inventory\Domain\Event\StockReserved;
use App\Inventory\Domain\InsufficientStock;
use App\Inventory\Domain\InvalidStockOperation;
use App\Inventory\Domain\StockBelowReserved;
use App\Inventory\Domain\StockItem;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use Codeception\Test\Unit;

/**
 * StockItem invariants S1-S4: the full reserve/release/commit edge table.
 * S1 (0 <= reserved <= onHand) is never violated in any reachable state —
 * every mutating method's guards exist to keep it true.
 */
final class StockItemTest extends Unit
{
    private const string ORDER_ID = '0197b5a2-0000-7000-8000-000000000001';

    private \DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');
    }

    public function testFreshItemHasNothing(): void
    {
        $item = StockItem::forSku(Sku::fromString('WIDGET-1'), $this->now);

        $this->assertSame(0, $item->onHand());
        $this->assertSame(0, $item->reserved());
        $this->assertSame(0, $item->available());
    }

    public function testSetOnHandRecordsStockReceivedWithDeltaAndAvailable(): void
    {
        $item = $this->itemWithStock(10);

        $events = $item->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(StockReceived::class, $event);
        $this->assertSame(10, $event->quantityDelta);
        $this->assertSame(10, $event->available);
    }

    public function testSetOnHandToSameLevelRecordsNothing(): void
    {
        $item = $this->itemWithStock(10);
        $item->releaseEvents();

        $item->setOnHand(10, $this->now);

        $this->assertCount(0, $item->releaseEvents());
    }

    public function testLoweringOnHandRecordsNegativeDelta(): void
    {
        $item = $this->itemWithStock(10);
        $item->releaseEvents();

        $item->setOnHand(4, $this->now);

        $events = $item->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(StockReceived::class, $event);
        $this->assertSame(-6, $event->quantityDelta);
        $this->assertSame(4, $event->available);
    }

    public function testNegativeOnHandIsRejected(): void
    {
        $item = $this->itemWithStock(10);

        $this->expectException(\InvalidArgumentException::class);
        $item->setOnHand(-1, $this->now);
    }

    // --- S4: on-hand cannot drop below reserved --------------------------

    public function testSetOnHandBelowReservedIsRejected(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(6), self::ORDER_ID, $this->now);

        $this->expectException(StockBelowReserved::class);
        $item->setOnHand(5, $this->now);
    }

    public function testSetOnHandExactlyAtReservedIsAllowed(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(6), self::ORDER_ID, $this->now);

        $item->setOnHand(6, $this->now);

        $this->assertSame(6, $item->onHand());
        $this->assertSame(0, $item->available());
    }

    // --- S2: reserve ------------------------------------------------------

    public function testReserveHoldsStockAndRecordsEvent(): void
    {
        $item = $this->itemWithStock(10);
        $item->releaseEvents();

        $item->reserve(Quantity::of(3), self::ORDER_ID, $this->now);

        $this->assertSame(10, $item->onHand());
        $this->assertSame(3, $item->reserved());
        $this->assertSame(7, $item->available());

        $events = $item->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(StockReserved::class, $event);
        $this->assertSame(self::ORDER_ID, $event->orderId);
        $this->assertSame(3, $event->quantity);
        $this->assertSame(7, $event->available);
    }

    public function testReserveExactlyAvailableIsAllowed(): void
    {
        $item = $this->itemWithStock(10);

        $item->reserve(Quantity::of(10), self::ORDER_ID, $this->now);

        $this->assertSame(0, $item->available());
    }

    public function testReserveMoreThanAvailableThrowsInsufficientStock(): void
    {
        $item = $this->itemWithStock(10);

        $this->expectException(InsufficientStock::class);
        $this->expectExceptionMessage('Insufficient stock for WIDGET-1.');
        $item->reserve(Quantity::of(11), self::ORDER_ID, $this->now);
    }

    public function testReserveCountsExistingReservations(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(8), self::ORDER_ID, $this->now);

        $this->expectException(InsufficientStock::class);
        $item->reserve(Quantity::of(3), 'another-order', $this->now);
    }

    // --- S3: release ------------------------------------------------------

    public function testReleaseReturnsStockToAvailability(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(4), self::ORDER_ID, $this->now);
        $item->releaseEvents();

        $item->release(Quantity::of(4), self::ORDER_ID, $this->now);

        $this->assertSame(10, $item->onHand());
        $this->assertSame(0, $item->reserved());
        $this->assertSame(10, $item->available());

        $events = $item->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(StockReleased::class, $event);
        $this->assertSame(10, $event->available);
    }

    public function testReleaseMoreThanReservedThrows(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(2), self::ORDER_ID, $this->now);

        $this->expectException(InvalidStockOperation::class);
        $item->release(Quantity::of(3), self::ORDER_ID, $this->now);
    }

    public function testReleaseWithNothingReservedThrows(): void
    {
        $item = $this->itemWithStock(10);

        $this->expectException(InvalidStockOperation::class);
        $item->release(Quantity::of(1), self::ORDER_ID, $this->now);
    }

    // --- S3: commit (shipment) -------------------------------------------

    public function testCommitDecrementsBothOnHandAndReserved(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(4), self::ORDER_ID, $this->now);
        $item->releaseEvents();

        $item->commit(Quantity::of(4), self::ORDER_ID, $this->now);

        $this->assertSame(6, $item->onHand());
        $this->assertSame(0, $item->reserved());
        $this->assertSame(6, $item->available());

        $events = $item->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(StockCommitted::class, $event);
        $this->assertSame(4, $event->quantity);
        $this->assertSame(6, $event->available);
    }

    public function testCommitMoreThanReservedThrows(): void
    {
        $item = $this->itemWithStock(10);
        $item->reserve(Quantity::of(2), self::ORDER_ID, $this->now);

        $this->expectException(InvalidStockOperation::class);
        $item->commit(Quantity::of(3), self::ORDER_ID, $this->now);
    }

    public function testCommitWithNothingReservedThrows(): void
    {
        $item = $this->itemWithStock(10);

        $this->expectException(InvalidStockOperation::class);
        $item->commit(Quantity::of(1), self::ORDER_ID, $this->now);
    }

    private function itemWithStock(int $onHand): StockItem
    {
        $item = StockItem::forSku(Sku::fromString('WIDGET-1'), $this->now);
        $item->setOnHand($onHand, $this->now);

        return $item;
    }
}
