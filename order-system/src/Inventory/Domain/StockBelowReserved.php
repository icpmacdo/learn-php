<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Invariant S4: an operator cannot set on-hand below what is already
 * reserved — those units are promised to placed orders. Surfaces as a 422.
 */
final class StockBelowReserved extends \DomainException
{
    public static function create(int $onHand, int $reserved): self
    {
        return new self(sprintf('Cannot set on-hand to %d: %d units are reserved.', $onHand, $reserved));
    }
}
