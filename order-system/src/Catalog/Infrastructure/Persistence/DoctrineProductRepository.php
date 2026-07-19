<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Domain\DuplicateSku;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductNotFound;
use App\Catalog\Domain\ProductRepository;
use App\Shared\Domain\Sku;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine adapter for the ProductRepository port. The interesting move is in
 * add(): the driver-level UniqueConstraintViolationException is translated to
 * the domain's DuplicateSku, so callers up the stack (Application handlers,
 * which may not import vendor code) catch a domain exception, not a Doctrine
 * one. The unique index — not a pre-check — enforces P2 without a race.
 */
final class DoctrineProductRepository implements ProductRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(Sku $sku): Product
    {
        return $this->bySkuOrNull($sku) ?? throw ProductNotFound::withSku($sku);
    }

    public function bySkuOrNull(Sku $sku): ?Product
    {
        return $this->em->find(Product::class, $sku);
    }

    public function add(Product $product): void
    {
        try {
            $this->em->persist($product);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            throw DuplicateSku::withSku($product->sku());
        }
    }

    public function save(Product $product): void
    {
        $this->em->flush();
    }
}
