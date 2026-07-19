<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Port: run a unit of work atomically.
 *
 * Checkout is the reason this exists: PlaceOrder persists the order, empties
 * the cart, and dispatches OrderPlaced — whose Inventory subscriber reserves
 * stock in the SAME transaction, so InsufficientStock rolls the whole
 * placement back. That reserve-or-rollback guarantee is exactly what part 4
 * takes away (and rebuilds with outbox + compensation); here it is one
 * database transaction behind a domain-owned port.
 */
interface TransactionBoundary
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function transactional(callable $work): mixed;
}
