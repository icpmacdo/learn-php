<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\Sku;

/** DELETE of a line that is not in the cart -> 404. */
final class CartLineNotFound extends \DomainException
{
    public static function forSku(Sku $sku): self
    {
        return new self(sprintf('No cart line for %s.', $sku->value));
    }
}
