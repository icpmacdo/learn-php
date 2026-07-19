<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\TransactionBoundary;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine adapter for the TransactionBoundary port.
 *
 * Deliberately NOT EntityManager::wrapInTransaction: that helper closes the
 * EntityManager on any exception, but here a rollback is often CONTROL FLOW,
 * not corruption — InsufficientStock aborting a checkout is a business
 * outcome the very same process (a test, a worker) may want to continue
 * after. So we manage the connection transaction ourselves and leave the EM
 * open; with use_savepoints enabled, nesting (e.g. the integration suite's
 * per-test wrapping transaction) degrades gracefully to savepoints.
 */
final class DoctrineTransactionBoundary implements TransactionBoundary
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }
}
