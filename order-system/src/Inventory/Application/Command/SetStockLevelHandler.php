<?php

declare(strict_types=1);

namespace App\Inventory\Application\Command;

use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockItemRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\Sku;
use App\Shared\Domain\TransactionBoundary;

/**
 * Upsert semantics on purpose: the warehouse can hold stock for a SKU the
 * catalog has never heard of — the contexts share nothing but the SKU value,
 * so there is no "product must exist" rule here (and must not be).
 *
 * Transactional since stage 3: StockReceived feeds the read_product_list
 * projector's availability column in the same transaction.
 */
final class SetStockLevelHandler
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /** @throws \App\Inventory\Domain\StockBelowReserved */
    public function handle(SetStockLevel $command): StockItem
    {
        return $this->transaction->transactional(function () use ($command): StockItem {
            $sku = Sku::fromString($command->sku);
            $now = $this->clock->now();

            // Locked read: the S4 guard (onHand >= reserved) is check-then-act
            // against the reserved count, which concurrent checkouts mutate.
            $stockItem = $this->stockItems->bySkuForUpdate($sku);
            if ($stockItem === null) {
                $stockItem = StockItem::forSku($sku, $now);
                $stockItem->setOnHand($command->onHand, $now);
                $this->stockItems->add($stockItem);
            } else {
                $stockItem->setOnHand($command->onHand, $now);
                $this->stockItems->save($stockItem);
            }

            $this->events->dispatch(...$stockItem->releaseEvents());

            return $stockItem;
        });
    }
}
