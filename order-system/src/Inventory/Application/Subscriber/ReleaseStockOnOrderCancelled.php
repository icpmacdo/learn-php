<?php

declare(strict_types=1);

namespace App\Inventory\Application\Subscriber;

use App\Inventory\Domain\ReservationRepository;
use App\Inventory\Domain\StockItemRepository;
use App\Ordering\Domain\Event\OrderCancelled;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;

/**
 * On OrderCancelled, give the held stock back: the Reservation flips to
 * `released` (R2 guards a double release) and each StockItem's reserved
 * count drops — availability visibly returns in the same transaction.
 */
final class ReleaseStockOnOrderCancelled
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly ReservationRepository $reservations,
        private readonly DomainEventDispatcher $events,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(OrderCancelled $event): void
    {
        $reservation = $this->reservations->byOrderIdOrNull($event->orderId);
        if ($reservation === null) {
            return; // nothing was ever reserved for this order
        }

        $now = $this->clock->now();
        $reservation->release($now); // R2: throws if already released/committed
        $this->reservations->save($reservation);

        foreach ($reservation->lines() as $line) {
            // Locked read: serialize with concurrent reserves/commits so the
            // absolute `reserved` value flushed below is never a lost update.
            $stockItem = $this->stockItems->bySkuForUpdate($line->sku());
            if ($stockItem === null) {
                continue; // unreachable: a reservation implies a stock record
            }
            $stockItem->release($line->quantity(), $event->orderId, $now);
            $this->stockItems->save($stockItem);
            $this->events->dispatch(...$stockItem->releaseEvents());
        }
    }
}
