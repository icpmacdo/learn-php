<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\TransactionBoundary;

/**
 * Cancel an order: free while placed, within the 24h refund window while
 * paid (the aggregate decides — the Clock's `now` just gets handed in).
 * OrderCancelled -> Inventory releases the reservation, same transaction.
 */
final class CancelOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound                               also for another customer's order (404)
     * @throws \App\Ordering\Domain\IllegalOrderTransition shipped/cancelled (409)
     * @throws \App\Ordering\Domain\RefundWindowClosed     paid too long ago (409)
     */
    public function handle(CancelOrder $command): Order
    {
        $orderId = OrderId::fromString($command->orderId);

        return $this->transaction->transactional(function () use ($orderId, $command): Order {
            // Locked read: cancel() guards on status in PHP, so serialize
            // with a concurrent pay/ship committing between read and flush.
            $order = $this->orders->byIdForUpdate($orderId);
            if ($order === null || !$order->customerId()->equals(CustomerId::fromString($command->customerId))) {
                throw OrderNotFound::withId($orderId);
            }

            $order->cancel($this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(...$order->releaseEvents());

            return $order;
        });
    }
}
