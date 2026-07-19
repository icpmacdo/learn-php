<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\StateConflict;

/**
 * Invariant O1's time-based half: a paid order may only be cancelled while
 * now < paidAt + 24h. Its own class (not a plain IllegalOrderTransition)
 * because paid->cancelled IS a legal transition — it is the WINDOW that shut,
 * and the 409 message should say so.
 */
final class RefundWindowClosed extends \DomainException implements StateConflict
{
}
