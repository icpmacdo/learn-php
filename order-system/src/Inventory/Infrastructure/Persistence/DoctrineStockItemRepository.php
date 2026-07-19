<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Domain\StockItem;
use App\Inventory\Domain\StockItemRepository;
use App\Shared\Domain\Sku;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineStockItemRepository implements StockItemRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function bySkuOrNull(Sku $sku): ?StockItem
    {
        return $this->em->find(StockItem::class, $sku);
    }

    public function bySkuForUpdate(Sku $sku): ?StockItem
    {
        // SELECT ... FOR UPDATE. On an identity-map hit find() locks the row
        // but keeps the in-memory state, so refresh() re-reads it now that
        // no concurrent transaction can be mid-write on it. The refresh MUST
        // carry the lock mode too: a plain refresh is a consistent read that
        // would resurrect the transaction's REPEATABLE READ snapshot — stale
        // data the row lock was acquired to avoid.
        $stockItem = $this->em->find(StockItem::class, $sku, LockMode::PESSIMISTIC_WRITE);
        if ($stockItem !== null) {
            $this->em->refresh($stockItem, LockMode::PESSIMISTIC_WRITE);
        }

        return $stockItem;
    }

    public function add(StockItem $stockItem): void
    {
        $this->em->persist($stockItem);
        $this->em->flush();
    }

    public function save(StockItem $stockItem): void
    {
        $this->em->flush();
    }
}
