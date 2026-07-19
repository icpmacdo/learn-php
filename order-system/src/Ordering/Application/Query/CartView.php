<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Shared\Domain\Money;

/**
 * The whole cart, priced live. `total` is null exactly when there are no
 * priceable lines — an empty cart has no currency to express a zero in.
 */
final class CartView
{
    /** @param list<CartLineView> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly ?Money $total,
    ) {
    }

    public static function empty(): self
    {
        return new self([], null);
    }
}
