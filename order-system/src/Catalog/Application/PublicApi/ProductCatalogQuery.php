<?php

declare(strict_types=1);

namespace App\Catalog\Application\PublicApi;

use App\Catalog\Domain\ProductRepository;
use App\Shared\Domain\Sku;

/**
 * Catalog's published query API — the ONE class other contexts' adapters may
 * call (deptrac allows exactly OrderingInfrastructure -> CatalogPublicApi).
 * It returns plain DTOs, never entities, and only serves ACTIVE products:
 * invariant P3 ("inactive products are unsellable") is enforced here, at the
 * context's front door, so consumers cannot even see an inactive product.
 */
final class ProductCatalogQuery
{
    public function __construct(
        private readonly ProductRepository $products,
    ) {
    }

    public function activeBySku(Sku $sku): ?ProductSummary
    {
        $product = $this->products->bySkuOrNull($sku);
        if ($product === null || !$product->isActive()) {
            return null;
        }

        return new ProductSummary(
            $product->sku()->value,
            $product->name(),
            $product->price()->amountMinor,
            $product->price()->currency,
        );
    }
}
