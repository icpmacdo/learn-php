<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * A reservation's lifecycle: active until it is released (order cancelled)
 * or committed (order shipped) — each reachable AT MOST ONCE (R2).
 */
enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Committed = 'committed';
}
