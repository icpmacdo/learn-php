<?php

declare(strict_types=1);

namespace App\Catalog\Application\Projection;

/**
 * Port: the write side of the read_product_list projection. Defined in the
 * Application layer (next to the projector that drives it) because deptrac
 * forbids Application classes any vendor import — the DBAL upserts live in
 * the Infrastructure adapter behind this interface.
 *
 * The upsert-shaped methods are deliberate: the two halves of the row arrive
 * from two different contexts in no guaranteed command order (an operator
 * may stock a SKU before the catalog has heard of it), so each side must be
 * able to touch its own columns whether or not the other side wrote first.
 */
interface ProductListReadModel
{
    /** Merchandising half (Catalog events); preserves any earlier availability. */
    public function upsertProduct(string $sku, string $name, int $priceMinor, string $currency, bool $active): void;

    public function setName(string $sku, string $name): void;

    public function setPrice(string $sku, int $priceMinor, string $currency): void;

    public function setActive(string $sku, bool $active): void;

    /** Availability half (Inventory events); creates a hidden row if the product is not yet known. */
    public function upsertAvailability(string $sku, int $available): void;
}
