<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\Sku;

/**
 * Port: persistence contract for StockItem aggregates.
 */
interface StockItemRepository
{
    public function bySkuOrNull(Sku $sku): ?StockItem;

    /**
     * Load with an EXCLUSIVE row lock, re-reading current state (never a
     * stale in-memory copy). Every mutation path must use this: the S1-S4
     * guards are check-then-act in PHP, so without the lock two concurrent
     * transactions could both pass the available() check and oversell the
     * same unit (a classic lost update). Requires an active transaction —
     * the lock is held until it commits or rolls back.
     */
    public function bySkuForUpdate(Sku $sku): ?StockItem;

    /** Persist a NEW stock item. */
    public function add(StockItem $stockItem): void;

    /** Flush changes made to an already-loaded stock item. */
    public function save(StockItem $stockItem): void;
}
