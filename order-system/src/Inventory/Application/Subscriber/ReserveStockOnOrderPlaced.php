<?php

declare(strict_types=1);

namespace App\Inventory\Application\Subscriber;

use App\Inventory\Domain\InsufficientStock;
use App\Inventory\Domain\NewReservationLine;
use App\Inventory\Domain\Reservation;
use App\Inventory\Domain\ReservationRepository;
use App\Inventory\Domain\StockItemRepository;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * Inventory's half of checkout: on OrderPlaced, reserve stock for every line
 * and record one Reservation for the order (R1).
 *
 * Runs synchronously INSIDE the checkout transaction (registered on the
 * dispatcher in services.yaml — Application classes carry no framework
 * attributes). If any line has too little stock, InsufficientStock flies
 * through the dispatcher, the TransactionBoundary rolls EVERYTHING back —
 * order, cart, partial reservations — and the API answers 409. A SKU with no
 * stock record at all is simply zero available: same exception, same
 * message, no special case for "the warehouse has never heard of it".
 *
 * This is a CONFORMIST integration: Inventory consumes Ordering's published
 * language (the event's scalars) and builds its own VOs from them.
 */
final class ReserveStockOnOrderPlaced
{
    public function __construct(
        private readonly StockItemRepository $stockItems,
        private readonly ReservationRepository $reservations,
        private readonly DomainEventDispatcher $events,
        private readonly Clock $clock,
    ) {
    }

    /** @throws InsufficientStock rolls the whole checkout back (409) */
    public function __invoke(OrderPlaced $event): void
    {
        $now = $this->clock->now();
        $reservationLines = [];
        $stockEvents = [];

        foreach ($event->lines as $line) {
            $sku = Sku::fromString($line['sku']);
            $quantity = Quantity::of($line['quantity']);

            // Locked read (SELECT ... FOR UPDATE): the S2 guard in reserve()
            // is check-then-act, so two concurrent checkouts must serialize
            // on the row or both could reserve the same last unit.
            $stockItem = $this->stockItems->bySkuForUpdate($sku)
                ?? throw InsufficientStock::forSku($sku); // no stock record = 0 available

            $stockItem->reserve($quantity, $event->orderId, $now); // throws InsufficientStock (S2)
            $this->stockItems->save($stockItem);

            $reservationLines[] = new NewReservationLine($sku, $quantity);
            foreach ($stockItem->releaseEvents() as $stockEvent) {
                $stockEvents[] = $stockEvent;
            }
        }

        $this->reservations->add(Reservation::forOrder($event->orderId, $reservationLines, $now));
        $this->events->dispatch(...$stockEvents);
    }
}
