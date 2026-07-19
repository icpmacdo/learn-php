<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Port: persistence contract for Reservation aggregates. Lookup is by ORDER
 * id (a plain cross-context string) because that is how the release/commit
 * subscribers find their reservation — the ReservationId itself never
 * travels anywhere.
 */
interface ReservationRepository
{
    public function byOrderIdOrNull(string $orderId): ?Reservation;

    /** Persist a NEW reservation (with its lines). */
    public function add(Reservation $reservation): void;

    /** Flush changes made to an already-loaded reservation. */
    public function save(Reservation $reservation): void;
}
