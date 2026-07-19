<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublicApi;

/**
 * The published shape of a catalog product: plain scalars, no entities.
 * This DTO — not the Product aggregate — is what leaves the Catalog context.
 */
final class ProductSummary
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly int $priceMinor,
        public readonly string $currency,
    ) {
    }
}
