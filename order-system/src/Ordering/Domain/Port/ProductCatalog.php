<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

use App\Shared\Domain\Sku;

/**
 * Port: Ordering's anti-corruption view of the catalog — the ONE synchronous
 * cross-context call in the system (customer/supplier relationship).
 *
 * Defined in Ordering's Domain and returning Ordering's OWN ProductSnapshot
 * VO: the adapter (Infrastructure/Acl) translates Catalog's published DTOs
 * into it, so Catalog's model never leaks in here. Serves ACTIVE products
 * only — invariant P3 ("inactive products are unsellable") arrives in
 * Ordering as a simple null.
 */
interface ProductCatalog
{
    public function activeProduct(Sku $sku): ?ProductSnapshot;
}
