<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

/**
 * Command: plain scalars at the application boundary. The handler converts
 * them to value objects — the domain never sees raw input.
 */
final class AddProduct
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly ?string $description,
        public readonly int $priceMinor,
        public readonly string $currency,
    ) {
    }
}
