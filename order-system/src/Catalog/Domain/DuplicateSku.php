<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Shared\Domain\Sku;

/**
 * Invariant P2: SKUs are unique. Enforced by a DB unique index (never a racy
 * SELECT-then-INSERT pre-check — part 1's lesson); the Doctrine adapter
 * translates the driver's UniqueConstraintViolationException into this domain
 * exception so the Application layer never sees vendor exception types.
 */
final class DuplicateSku extends \DomainException
{
    public static function withSku(Sku $sku): self
    {
        return new self(sprintf('A product with SKU %s already exists.', $sku->value));
    }
}
