<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Inventory\Domain\Event\StockCommitted;
use App\Inventory\Domain\Event\StockReceived;
use App\Inventory\Domain\Event\StockReleased;
use App\Inventory\Domain\Event\StockReserved;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\RecordsEvents;
use App\Shared\Domain\Sku;

/**
 * Inventory's model of "a thing we sell": warehouse truth for one SKU.
 * Deliberately knows quantities only — no price, no name, no description;
 * a warehouse doesn't care what things cost. Same SKU, different object than
 * Catalog's Product: that is the bounded-context lesson in one class.
 *
 * Invariants (PRD S1-S4), all enforced here and only here:
 *   S1  0 <= reserved <= onHand at all times.
 *   S2  reserve(qty) throws InsufficientStock when available() < qty.
 *   S3  release() cannot exceed reserved; commit() (shipment) decrements
 *       BOTH onHand and reserved — stock leaves the building.
 *   S4  setOnHand() below reserved is rejected (StockBelowReserved -> 422).
 *
 * Every event payload carries available() so projectors never look back.
 */
class StockItem
{
    use RecordsEvents;

    private Sku $sku;
    private int $onHand;
    private int $reserved;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(Sku $sku, \DateTimeImmutable $now)
    {
        $this->sku = $sku;
        $this->onHand = 0;
        $this->reserved = 0;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** A fresh stock record: nothing on hand, nothing reserved. */
    public static function forSku(Sku $sku, \DateTimeImmutable $now): self
    {
        return new self($sku, $now);
    }

    /**
     * Operator sets the absolute on-hand level (PUT /api/stock/{sku}).
     *
     * @throws StockBelowReserved when the new level is below reserved (S4)
     */
    public function setOnHand(int $onHand, \DateTimeImmutable $now): void
    {
        if ($onHand < 0) {
            throw new \InvalidArgumentException('On-hand stock cannot be negative.');
        }
        if ($onHand < $this->reserved) {
            throw StockBelowReserved::create($onHand, $this->reserved);
        }

        $delta = $onHand - $this->onHand;
        if ($delta === 0) {
            return;
        }

        $this->onHand = $onHand;
        $this->touch($now);
        $this->recordThat(new StockReceived($this->sku, $delta, $this->available()));
    }

    /**
     * Hold stock against a placed order.
     *
     * @throws InsufficientStock when available() < qty (S2)
     */
    public function reserve(Quantity $quantity, string $orderId, \DateTimeImmutable $now): void
    {
        if ($this->available() < $quantity->value) {
            throw InsufficientStock::forSku($this->sku);
        }

        $this->reserved += $quantity->value;
        $this->touch($now);
        $this->recordThat(new StockReserved($this->sku, $orderId, $quantity->value, $this->available()));
    }

    /**
     * Return reserved stock to availability (order cancelled).
     *
     * @throws InvalidStockOperation when releasing more than is reserved (S3)
     */
    public function release(Quantity $quantity, string $orderId, \DateTimeImmutable $now): void
    {
        if ($quantity->value > $this->reserved) {
            throw new InvalidStockOperation(sprintf('Cannot release %d units of %s: only %d reserved.', $quantity->value, $this->sku->value, $this->reserved));
        }

        $this->reserved -= $quantity->value;
        $this->touch($now);
        $this->recordThat(new StockReleased($this->sku, $orderId, $quantity->value, $this->available()));
    }

    /**
     * Ship reserved stock out of the warehouse: onHand AND reserved drop by
     * qty, keeping S1 honest end-to-end (S3).
     *
     * @throws InvalidStockOperation when committing more than is reserved
     */
    public function commit(Quantity $quantity, string $orderId, \DateTimeImmutable $now): void
    {
        if ($quantity->value > $this->reserved) {
            throw new InvalidStockOperation(sprintf('Cannot commit %d units of %s: only %d reserved.', $quantity->value, $this->sku->value, $this->reserved));
        }

        $this->onHand -= $quantity->value;
        $this->reserved -= $quantity->value;
        $this->touch($now);
        $this->recordThat(new StockCommitted($this->sku, $orderId, $quantity->value, $this->available()));
    }

    public function sku(): Sku
    {
        return $this->sku;
    }

    public function onHand(): int
    {
        return $this->onHand;
    }

    public function reserved(): int
    {
        return $this->reserved;
    }

    public function available(): int
    {
        return $this->onHand - $this->reserved;
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }
}
