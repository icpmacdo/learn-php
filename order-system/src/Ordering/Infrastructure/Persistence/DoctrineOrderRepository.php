<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Persistence;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine adapter for the OrderRepository port. The Order and its lines are
 * XML-mapped (config/doctrine/Ordering/) with cascade-persist on the lines,
 * so add() persists the whole aggregate in one go — child entities are an
 * implementation detail the port never mentions.
 */
final class DoctrineOrderRepository implements OrderRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function byIdOrNull(OrderId $id): ?Order
    {
        return $this->em->find(Order::class, $id);
    }

    public function byIdForUpdate(OrderId $id): ?Order
    {
        // SELECT ... FOR UPDATE. On an identity-map hit find() locks the row
        // but keeps the in-memory state, so refresh() re-reads it now that
        // no concurrent transaction can be mid-write on it. The refresh MUST
        // carry the lock mode too: a plain refresh is a consistent read that
        // would resurrect the transaction's REPEATABLE READ snapshot — stale
        // data the row lock was acquired to avoid.
        $order = $this->em->find(Order::class, $id, LockMode::PESSIMISTIC_WRITE);
        if ($order !== null) {
            $this->em->refresh($order, LockMode::PESSIMISTIC_WRITE);
        }

        return $order;
    }

    public function get(OrderId $id): Order
    {
        return $this->byIdOrNull($id) ?? throw OrderNotFound::withId($id);
    }

    public function add(Order $order): void
    {
        $this->em->persist($order);
        $this->em->flush();
    }

    public function save(Order $order): void
    {
        $this->em->flush();
    }
}
