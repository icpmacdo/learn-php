<?php

declare(strict_types=1);

namespace App\Inventory\Application\Subscriber;

use App\Inventory\Domain\ReservationRepository;
use App\Inventory\Domain\StockItemRepository;
use App\Ordering\Domain\Event\OrderShipped;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;

/**
 * On OrderShipped, the held stock actually leaves the building: the
 * Reservation flips to `committed` (R2) and each StockItem drops BOTH onHand
 * and reserved — which is what keeps S1 (0 <= reserved <= onHand) honest
 * end-to-end instead of reserved leaking forever.
 */
final class CommitStockOnOrderShipped
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly ReservationRepository $reservations,
        private readonly DomainEventDispatcher $events,
        private readonly Clock $clock,
    ) {
    }

    public function __invoke(OrderShipped $event): void
    {
        $reservation = $this->reservations->byOrderIdOrNull($event->orderId);
        if ($reservation === null) {
            return; // nothing was ever reserved for this order
        }

        $now = $this->clock->now();
        $reservation->commit($now); // R2: throws if already released/committed
        $this->reservations->save($reservation);

        foreach ($reservation->lines() as $line) {
            // Locked read: serialize with concurrent reserves/releases so the
            // absolute values flushed below are never a lost update.
            $stockItem = $this->stockItems->bySkuForUpdate($line->sku());
            if ($stockItem === null) {
                continue; // unreachable: a reservation implies a stock record
            }
            $stockItem->commit($line->quantity(), $event->orderId, $now);
            $this->stockItems->save($stockItem);
            $this->events->dispatch(...$stockItem->releaseEvents());
        }
    }
}
