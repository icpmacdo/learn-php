<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockItemRepository;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineStockItemRepositoryCest
{
    public function roundTripsAStockItemWithReservation(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $sku = Sku::fromString('IT-'.strtoupper(bin2hex(random_bytes(4))));
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');

        $item = StockItem::forSku($sku, $now);
        $item->setOnHand(10, $now);
        $item->reserve(Quantity::of(3), 'order-1', $now);
        $repo->add($item);
        $this->em($I)->clear();

        $reloaded = $repo->bySkuOrNull($sku);
        $I->assertNotNull($reloaded);
        $I->assertSame(10, $reloaded->onHand());
        $I->assertSame(3, $reloaded->reserved());
        $I->assertSame(7, $reloaded->available());
    }

    public function savePersistsMutations(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $sku = Sku::fromString('IT-'.strtoupper(bin2hex(random_bytes(4))));
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');

        $item = StockItem::forSku($sku, $now);
        $item->setOnHand(5, $now);
        $repo->add($item);

        $loaded = $repo->bySkuOrNull($sku);
        $I->assertNotNull($loaded);
        $loaded->setOnHand(12, $now);
        $repo->save($loaded);
        $this->em($I)->clear();

        $reloaded = $repo->bySkuOrNull($sku);
        $I->assertNotNull($reloaded);
        $I->assertSame(12, $reloaded->onHand());
    }

    public function unknownSkuIsNull(IntegrationTester $I): void
    {
        $I->assertNull($this->repo($I)->bySkuOrNull(Sku::fromString('NOPE-'.strtoupper(bin2hex(random_bytes(4))))));
    }

    private function repo(IntegrationTester $I): StockItemRepository
    {
        return $I->grabService(StockItemRepository::class);
    }

    private function em(IntegrationTester $I): EntityManagerInterface
    {
        return $I->grabService(EntityManagerInterface::class);
    }
}
