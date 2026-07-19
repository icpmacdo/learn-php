<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\StateConflict;

/**
 * Invariant O1: the state machine rejected a transition. StateConflict marker
 * -> 409, the pinned "valid request, conflicting state" status.
 */
final class IllegalOrderTransition extends \DomainException implements StateConflict
{
    public static function attempted(string $action, OrderStatus $current): self
    {
        return new self(sprintf('Cannot %s an order that is %s.', $action, $current->value));
    }
}
