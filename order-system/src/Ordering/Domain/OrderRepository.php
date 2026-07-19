<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Port: persistence contract for Order aggregates.
 *
 * Deliberately NO listing methods: order history is a read-model concern
 * (read_order_summary, stage 3) — the repository loads one aggregate to
 * mutate it or show its detail, nothing more.
 */
interface OrderRepository
{
    public function byIdOrNull(OrderId $id): ?Order;

    /**
     * Load with an EXCLUSIVE row lock, re-reading current state (never a
     * stale in-memory copy). Every state transition must go through this:
     * the O1/O5 guards run in PHP, so without the lock two concurrent
     * requests (double-click pay, cancel racing a payment) could both pass
     * a guard on stale state and flush a transition the aggregate forbids.
     * Requires an active transaction — the lock is held until it ends.
     */
    public function byIdForUpdate(OrderId $id): ?Order;

    /** @throws OrderNotFound */
    public function get(OrderId $id): Order;

    /** Persist a NEW order (with its lines). */
    public function add(Order $order): void;

    /** Flush changes made to an already-loaded order. */
    public function save(Order $order): void;
}
