<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * A child entity of Order, reachable only through the aggregate root.
 *
 * Ordering's model of "a thing we sell": the SKU plus the name and unit
 * price AS SNAPSHOTTED at placement. Deliberately distinct from Catalog's
 * Product (live merchandising truth) and Inventory's StockItem (warehouse
 * truth) — the only fact all three share is the SKU. Immutable: no method on
 * this class or its aggregate can change a line after placement (O2/O3 rest
 * on that).
 *
 * Constructed exclusively by Order::place(); the surrogate $id exists only
 * because rows need identity — it never leaves the aggregate.
 */
class OrderLine
{
    private ?int $id = null;

    public function __construct(
        private readonly Order $order,
        private readonly Sku $sku,
        private readonly string $name,
        private readonly Money $unitPrice,
        private readonly Quantity $quantity,
    ) {
    }

    public function sku(): Sku
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    public function lineTotal(): Money
    {
        return $this->unitPrice->multiplyBy($this->quantity);
    }

    /** The owning aggregate root (also the Doctrine association back to it). */
    public function order(): Order
    {
        return $this->order;
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
