<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The order state machine's states. A pure PHP backed enum — enums are
 * language, not framework, so the domain may use them freely.
 *
 * Legal transitions (invariant O1, enforced by the Order aggregate):
 *   placed -> paid, placed -> cancelled,
 *   paid -> shipped, paid -> cancelled (only inside the 24h refund window).
 * shipped and cancelled are terminal.
 */
enum OrderStatus: string
{
    case Placed = 'placed';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Cancelled = 'cancelled';
}
