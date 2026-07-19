<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Acl;

use App\Catalog\Application\PublicApi\ProductCatalogQuery;
use App\Ordering\Domain\Port\ProductCatalog;
use App\Ordering\Domain\Port\ProductSnapshot;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;

/**
 * The anti-corruption layer made concrete: adapter for Ordering's
 * ProductCatalog port that calls Catalog's ONE published class
 * (ProductCatalogQuery) and translates its DTO into Ordering's own
 * ProductSnapshot VO.
 *
 * This class is the single legal cross-context call in the codebase —
 * deptrac allows exactly OrderingInfrastructure -> CatalogPublicApi and
 * nothing else. If Catalog ever reshapes its published DTO, this adapter is
 * the blast radius; Ordering's domain never notices.
 */
final class CatalogProductCatalog implements ProductCatalog
{
    public function __construct(
        private readonly ProductCatalogQuery $catalog,
    ) {
    }

    public function activeProduct(Sku $sku): ?ProductSnapshot
    {
        $summary = $this->catalog->activeBySku($sku);
        if ($summary === null) {
            return null;
        }

        return new ProductSnapshot(
            Sku::fromString($summary->sku),
            $summary->name,
            Money::of($summary->priceMinor, $summary->currency),
        );
    }
}
