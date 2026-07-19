<?php

declare(strict_types=1);

namespace App\Ordering\Application\Projection;

use App\Ordering\Domain\Event\OrderCancelled;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderShipped;
use App\Ordering\Domain\OrderStatus;

/**
 * Maintains read_order_summary — the flat row behind GET /api/orders. Order
 * history is a status/total listing that should not hydrate full Order
 * aggregates (lines and all) just to render a table; that is where this
 * projection earns its keep.
 *
 * Subscribed to all four order events (services.yaml), synchronously inside
 * the emitting command's transaction: the summary can never disagree with
 * the write model in part 3. Part 4 makes exactly this eventually
 * consistent.
 */
final class OrderSummaryProjector
{
    public function __construct(
        private readonly OrderSummaryReadModel $readModel,
    ) {
    }

    public function onOrderPlaced(OrderPlaced $event): void
    {
        $this->readModel->insert(
            $event->orderId,
            $event->customerId,
            OrderStatus::Placed->value,
            $event->totalMinor,
            $event->currency,
            \count($event->lines),
            $event->placedAt,
        );
    }

    public function onOrderPaid(OrderPaid $event): void
    {
        $this->readModel->updateStatus($event->orderId, OrderStatus::Paid->value, $event->paidAt);
    }

    public function onOrderShipped(OrderShipped $event): void
    {
        $this->readModel->updateStatus($event->orderId, OrderStatus::Shipped->value, $event->shippedAt);
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $this->readModel->updateStatus($event->orderId, OrderStatus::Cancelled->value, $event->cancelledAt);
    }
}
