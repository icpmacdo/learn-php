<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\CustomerId;

/**
 * Port: persistence contract for Cart aggregates (one per customer).
 */
interface CartRepository
{
    public function byCustomerOrNull(CustomerId $customerId): ?Cart;

    /**
     * Load with an EXCLUSIVE row lock on the cart, reading current state.
     * Checkout must use this: consuming the cart is check-then-act (read
     * lines, place order, clear), so two devices racing POST /api/orders on
     * one cart would otherwise both see it non-empty and place the order
     * twice. Requires an active transaction — the lock is held until it ends.
     */
    public function byCustomerForUpdate(CustomerId $customerId): ?Cart;

    /** Upsert: persists new carts and every later mutation alike. */
    public function save(Cart $cart): void;
}
