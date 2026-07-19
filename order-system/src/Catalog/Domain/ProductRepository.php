<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Shared\Domain\Sku;

/**
 * Port: how the Catalog context persists and retrieves Product aggregates.
 * An interface in Domain, implemented by a Doctrine adapter in
 * Infrastructure — the domain decides the contract, the adapter obeys it.
 */
interface ProductRepository
{
    /** @throws ProductNotFound */
    public function get(Sku $sku): Product;

    public function bySkuOrNull(Sku $sku): ?Product;

    /**
     * Persist a NEW product.
     *
     * @throws DuplicateSku when the SKU already exists (unique index, P2)
     */
    public function add(Product $product): void;

    /** Flush changes made to an already-loaded product via aggregate methods. */
    public function save(Product $product): void;
}
