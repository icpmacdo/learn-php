<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\Cart;
use App\Ordering\Domain\CartRepository;
use App\Shared\Domain\CustomerId;
use Doctrine\DBAL\Connection;

/**
 * DBAL adapter for the CartRepository port — deliberately NOT the ORM.
 *
 * A cart is a scalar map (sku -> qty) that mutates line-by-line; mapping
 * that through ORM collections buys change-tracking machinery the aggregate
 * doesn't want (the domain would need vendor Collection types, which deptrac
 * forbids). Plain SQL against the same Connection the EntityManager uses
 * keeps carts inside every surrounding transaction (checkout's cart-clearing
 * rolls back with the order) while the domain stays a pure array. The port
 * is the point: the aggregate cannot tell the difference.
 *
 * save() rewrites the (<= 50) line rows wholesale — simpler than diffing —
 * inside its OWN transaction, so the destructive DELETE-then-INSERT window
 * can never commit half-done (a crash between the two would otherwise
 * silently empty the cart on the direct PUT/DELETE paths, which run without
 * a surrounding TransactionBoundary). Under checkout's outer transaction
 * this degrades to a savepoint (use_savepoints), so the cart-clearing still
 * rolls back with the order.
 */
final class DbalCartRepository implements CartRepository
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function byCustomerOrNull(CustomerId $customerId): ?Cart
    {
        return $this->load($customerId, forUpdate: false);
    }

    public function byCustomerForUpdate(CustomerId $customerId): ?Cart
    {
        return $this->load($customerId, forUpdate: true);
    }

    private function load(CustomerId $customerId, bool $forUpdate): ?Cart
    {
        // FOR UPDATE serializes concurrent checkouts of one cart on its row:
        // the loser blocks here, then reads CURRENT state (locking reads see
        // the latest commit, and the lines query below establishes its
        // snapshot only after the lock is granted) — an already-consumed
        // cart is seen empty, not re-ordered.
        /** @var array{currency: string|null, created_at: string, updated_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            'SELECT currency, created_at, updated_at FROM ordering_cart WHERE customer_id = :customerId'
                .($forUpdate ? ' FOR UPDATE' : ''),
            ['customerId' => $customerId->value],
        );
        if ($row === false) {
            return null;
        }

        /** @var array<string, int|string> $lines */
        $lines = $this->connection->fetchAllKeyValue(
            'SELECT sku, quantity FROM ordering_cart_line WHERE cart_id = :customerId ORDER BY sku',
            ['customerId' => $customerId->value],
        );

        return Cart::restore(
            $customerId,
            $row['currency'],
            array_map(intval(...), $lines),
            new \DateTimeImmutable($row['created_at']),
            new \DateTimeImmutable($row['updated_at']),
        );
    }

    public function save(Cart $cart): void
    {
        $this->connection->transactional(function () use ($cart): void {
            $customerId = $cart->customerId()->value;

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO ordering_cart (customer_id, currency, created_at, updated_at)
                    VALUES (:customerId, :currency, :createdAt, :updatedAt) AS new
                    ON DUPLICATE KEY UPDATE currency = new.currency, updated_at = new.updated_at
                    SQL,
                [
                    'customerId' => $customerId,
                    'currency' => $cart->currency(),
                    'createdAt' => $cart->createdAt()->format('Y-m-d H:i:s'),
                    'updatedAt' => $cart->updatedAt()->format('Y-m-d H:i:s'),
                ],
            );

            $this->connection->executeStatement(
                'DELETE FROM ordering_cart_line WHERE cart_id = :customerId',
                ['customerId' => $customerId],
            );
            foreach ($cart->lines() as $sku => $quantity) {
                $this->connection->executeStatement(
                    'INSERT INTO ordering_cart_line (cart_id, sku, quantity) VALUES (:customerId, :sku, :quantity)',
                    ['customerId' => $customerId, 'sku' => $sku, 'quantity' => $quantity],
                );
            }
        });
    }
}
