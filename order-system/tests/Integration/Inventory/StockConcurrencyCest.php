<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Domain\StockItemRepository;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Invariant S2 under CONCURRENCY. The aggregate's available() guard is
 * check-then-act in PHP, so two php-fpm workers reserving the same last unit
 * would both pass it unless the row is read under an exclusive lock —
 * regression tests for the oversell/lost-update bug:
 *
 *  - bySkuForUpdate() must really hold a row lock (probed from a SECOND
 *    connection with FOR UPDATE NOWAIT — deterministic, no sleeping),
 *  - the DB CHECK backstop rejects any write that would corrupt S1.
 */
final class StockConcurrencyCest
{
    public function bySkuForUpdateHoldsAnExclusiveRowLock(IntegrationTester $I): void
    {
        $skuValue = 'LOCK-'.strtoupper(bin2hex(random_bytes(4)));

        // Seed on a SECOND connection (autocommit): the row must be COMMITTED
        // for the probe below to contend on the row lock rather than on the
        // test transaction's uncommitted insert. The row stays behind in
        // app_test — random SKU, harmless, same policy as the acceptance
        // suite's accumulating dev data.
        $probe = $this->separateConnection($I);

        try {
            $probe->executeStatement(
                'INSERT INTO inventory_stock_item (sku, on_hand, reserved, created_at, updated_at) VALUES (?, 5, 0, NOW(), NOW())',
                [$skuValue],
            );

            // Locked read inside the test's wrapping transaction:
            $locked = $I->grabService(StockItemRepository::class)->bySkuForUpdate(Sku::fromString($skuValue));
            $I->assertNotNull($locked);
            $I->assertSame(5, $locked->onHand());

            // A concurrent transaction must NOT be able to grab the row now.
            // NOWAIT makes MySQL answer immediately instead of queueing:
            $I->expectThrowable(DriverException::class, static function () use ($probe, $skuValue): void {
                $probe->transactional(static function (Connection $connection) use ($skuValue): void {
                    $connection->executeQuery(
                        'SELECT sku FROM inventory_stock_item WHERE sku = ? FOR UPDATE NOWAIT',
                        [$skuValue],
                    );
                });
            });
        } finally {
            $probe->close();
        }
    }

    public function theDatabaseCheckRejectsWritesViolatingS1(IntegrationTester $I): void
    {
        $skuValue = 'CHK-'.strtoupper(bin2hex(random_bytes(4)));
        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();

        $connection->executeStatement(
            'INSERT INTO inventory_stock_item (sku, on_hand, reserved, created_at, updated_at) VALUES (?, 5, 0, NOW(), NOW())',
            [$skuValue],
        );

        // reserved > on_hand can never be flushed, whatever the PHP layer does:
        $I->expectThrowable(DriverException::class, static function () use ($connection, $skuValue): void {
            $connection->executeStatement(
                'UPDATE inventory_stock_item SET reserved = on_hand + 1 WHERE sku = ?',
                [$skuValue],
            );
        });
    }

    private function separateConnection(IntegrationTester $I): Connection
    {
        $params = $I->grabService(EntityManagerInterface::class)->getConnection()->getParams();

        return DriverManager::getConnection($params);
    }
}
