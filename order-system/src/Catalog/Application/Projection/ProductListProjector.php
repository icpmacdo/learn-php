<?php

declare(strict_types=1);

namespace App\Catalog\Application\Projection;

use App\Catalog\Domain\Event\ProductAdded;
use App\Catalog\Domain\Event\ProductDeactivated;
use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductReactivated;
use App\Catalog\Domain\Event\ProductRenamed;
use App\Inventory\Domain\Event\StockCommitted;
use App\Inventory\Domain\Event\StockReceived;
use App\Inventory\Domain\Event\StockReleased;
use App\Inventory\Domain\Event\StockReserved;

/**
 * Maintains read_product_list — the projection that lets GET /api/products
 * show price AND availability without a cross-context join at request time.
 * This is where CQRS-lite earns its keep in this project: the listing
 * composes two bounded contexts, and the composition happens at WRITE time
 * through their published events, keeping the read query single-table and
 * deptrac-legal.
 *
 * Two event streams feed one row:
 *  - Catalog events own the merchandising columns (name, price, active),
 *  - Inventory stock events own `available` — every stock event carries the
 *    new availability in its payload, so this projector needs no lookback
 *    into Inventory (it could not legally do one anyway).
 *
 * Wired in services.yaml (one tag per event, method per shape); runs
 * synchronously inside the emitting command's transaction, so during
 * checkout the StockReserved availability drop commits — or rolls back —
 * together with the order itself.
 */
final class ProductListProjector
{
    public function __construct(
        private readonly ProductListReadModel $readModel,
    ) {
    }

    public function onProductAdded(ProductAdded $event): void
    {
        $this->readModel->upsertProduct(
            $event->sku->value,
            $event->name,
            $event->price->amountMinor,
            $event->price->currency,
            true,
        );
    }

    public function onProductRenamed(ProductRenamed $event): void
    {
        $this->readModel->setName($event->sku->value, $event->name);
    }

    public function onProductPriceChanged(ProductPriceChanged $event): void
    {
        $this->readModel->setPrice($event->sku->value, $event->price->amountMinor, $event->price->currency);
    }

    public function onProductDeactivated(ProductDeactivated $event): void
    {
        $this->readModel->setActive($event->sku->value, false);
    }

    public function onProductReactivated(ProductReactivated $event): void
    {
        $this->readModel->setActive($event->sku->value, true);
    }

    public function onStockChanged(StockReceived|StockReserved|StockReleased|StockCommitted $event): void
    {
        $this->readModel->upsertAvailability($event->sku->value, $event->available);
    }
}
