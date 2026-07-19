<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Command for PATCH /api/products/{sku}: every field optional; price comes
 * as a minor-amount + currency pair (validated as a pair at the HTTP edge).
 */
final class UpdateProduct
{
    public function __construct(
        public readonly string $sku,
        public readonly ?string $name,
        public readonly ?int $priceMinor,
        public readonly ?string $currency,
        public readonly ?bool $active,
    ) {
    }
}
