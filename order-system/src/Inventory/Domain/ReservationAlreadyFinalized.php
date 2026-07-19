<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Invariant R2: release/commit on a non-active reservation. Deliberately NOT
 * a StateConflict/409 — the order state machine makes this unreachable via
 * the API (a cancelled order cannot ship), so if it ever fires it is a BUG
 * and should be a loud 500, not a polite conflict.
 */
final class ReservationAlreadyFinalized extends \DomainException
{
    public static function cannot(string $action, ReservationStatus $current): self
    {
        return new self(sprintf('Cannot %s a reservation that is already %s.', $action, $current->value));
    }
}
