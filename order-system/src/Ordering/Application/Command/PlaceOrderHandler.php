<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\CartRepository;
use App\Ordering\Domain\EmptyCart;
use App\Ordering\Domain\NewOrderLine;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\Port\ProductCatalog;
use App\Ordering\Domain\ProductNoLongerAvailable;
use App\Shared\Domain\Clock;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Shared\Domain\TransactionBoundary;

/**
 * Checkout — the one place where three contexts meet in a single ACID story:
 *
 *   1. price the cart's lines LIVE through the ProductCatalog ACL and
 *      snapshot them into an Order (C2: the order, not the cart, freezes
 *      prices),
 *   2. persist the placed order and consume the cart,
 *   3. dispatch OrderPlaced — Inventory's subscriber reserves stock IN THE
 *      SAME TRANSACTION.
 *
 * Everything runs inside the TransactionBoundary: if Inventory throws
 * InsufficientStock, the order, the cart-clearing and any partial
 * reservations all roll back atomically — the customer keeps the cart and
 * gets a 409. This is exactly the guarantee part 4 takes away.
 */
final class PlaceOrderHandler
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly OrderRepository $orders,
        private readonly ProductCatalog $catalog,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Note: Inventory's InsufficientStock also erupts through this method —
     * thrown by the OrderPlaced subscriber, rolling the checkout back (409).
     * It is deliberately not an @throws type reference here: Ordering's
     * Application layer may not depend on Inventory's Domain, not even in a
     * docblock. It reaches HTTP via the shared StateConflict marker.
     *
     * @throws EmptyCart
     * @throws ProductNoLongerAvailable
     */
    public function handle(PlaceOrder $command): Order
    {
        $customerId = CustomerId::fromString($command->customerId);

        return $this->transaction->transactional(function () use ($customerId): Order {
            $now = $this->clock->now();

            // Locked read: consuming the cart is check-then-act, so two
            // devices racing checkout serialize here — the loser re-reads
            // the consumed cart as empty (422), never a duplicate order.
            $cart = $this->carts->byCustomerForUpdate($customerId);
            if ($cart === null || $cart->isEmpty()) {
                throw new EmptyCart('Cart is empty.');
            }

            $lines = [];
            foreach ($cart->lines() as $skuValue => $quantity) {
                $sku = Sku::fromString($skuValue);
                $snapshot = $this->catalog->activeProduct($sku)
                    ?? throw ProductNoLongerAvailable::forSku($sku);
                if ($cart->currency() !== null && $snapshot->unitPrice->currency !== $cart->currency()) {
                    // Re-priced into another currency since it was added:
                    // the same "world changed underneath the cart" conflict.
                    throw ProductNoLongerAvailable::forSku($sku);
                }
                $lines[] = new NewOrderLine($sku, $snapshot->name, $snapshot->unitPrice, Quantity::of($quantity));
            }

            $order = Order::place($customerId, $lines, $now);
            $this->orders->add($order);

            $cart->clear($now);
            $this->carts->save($cart);

            $this->events->dispatch(...$order->releaseEvents());

            return $order;
        });
    }
}
