<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Shared\Domain\Sku;

final class ProductNotFound extends \DomainException
{
    public static function withSku(Sku $sku): self
    {
        return new self(sprintf('Product %s not found.', $sku->value));
    }
}
