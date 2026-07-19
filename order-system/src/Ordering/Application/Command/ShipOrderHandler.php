<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\TransactionBoundary;

/**
 * Operator marks a paid order shipped (admin-ish: no customer scoping).
 * OrderShipped -> Inventory COMMITS the reservation (onHand and reserved
 * both drop — the stock has left the building), same transaction.
 */
final class ShipOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound                               (404)
     * @throws \App\Ordering\Domain\IllegalOrderTransition       not paid (409)
     */
    public function handle(ShipOrder $command): Order
    {
        $orderId = OrderId::fromString($command->orderId);

        return $this->transaction->transactional(function () use ($orderId): Order {
            // Locked read: ship() guards on status in PHP, so serialize with
            // a concurrent cancel committing between read and flush.
            $order = $this->orders->byIdForUpdate($orderId)
                ?? throw OrderNotFound::withId($orderId);
            $order->ship($this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(...$order->releaseEvents());

            return $order;
        });
    }
}
