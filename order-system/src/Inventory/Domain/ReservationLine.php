<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * A child entity of Reservation: how much of one SKU is held. Immutable —
 * created at reservation time, only ever read back to release or commit the
 * exact same amounts. Knows quantities, never prices (warehouse truth).
 */
class ReservationLine
{
    private ?int $id = null;

    public function __construct(
        private readonly Reservation $reservation,
        private readonly Sku $sku,
        private readonly Quantity $quantity,
    ) {
    }

    public function sku(): Sku
    {
        return $this->sku;
    }

    public function quantity(): Quantity
    {
        return $this->quantity;
    }

    /** The owning aggregate root (also the Doctrine association back to it). */
    public function reservation(): Reservation
    {
        return $this->reservation;
    }

    public function id(): ?int
    {
        return $this->id;
    }
}
