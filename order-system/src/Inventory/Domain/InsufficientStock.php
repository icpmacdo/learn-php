<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\Sku;
use App\Shared\Domain\StateConflict;

/**
 * Invariant S2: a reservation cannot exceed available stock. Thrown inside
 * the checkout transaction (by the OrderPlaced subscriber), it rolls the
 * whole placement back and — via the StateConflict marker — surfaces as the
 * PRD's 409 "Insufficient stock for <sku>.".
 */
final class InsufficientStock extends \DomainException implements StateConflict
{
    public static function forSku(Sku $sku): self
    {
        return new self(sprintf('Insufficient stock for %s.', $sku->value));
    }
}
