<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Domain\DuplicateSku;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductName;
use App\Catalog\Domain\ProductNotFound;
use App\Catalog\Domain\ProductRepository;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The likeliest breakage of the "pure entities via XML mappings" move is the
 * object/relational boundary itself — so these tests round-trip the aggregate
 * through real MySQL: custom SKU id type, Money embeddable, unique index
 * translated to the domain's DuplicateSku.
 */
final class DoctrineProductRepositoryCest
{
    public function roundTripsAProductThroughMysql(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $sku = Sku::fromString('IT-'.strtoupper(bin2hex(random_bytes(4))));

        $repo->add(Product::add(
            $sku,
            ProductName::fromString('Integration Widget'),
            'Round-trip me.',
            Money::of(1234, 'EUR'),
            new \DateTimeImmutable('2026-07-18T12:00:00+00:00'),
        ));
        $this->em($I)->clear(); // force a real DB read, not identity-map echo

        $reloaded = $repo->get($sku);

        $I->assertTrue($reloaded->sku()->equals($sku));
        $I->assertSame('Integration Widget', $reloaded->name());
        $I->assertSame('Round-trip me.', $reloaded->description());
        $I->assertTrue($reloaded->price()->equals(Money::of(1234, 'EUR')));
        $I->assertTrue($reloaded->isActive());
    }

    public function savePersistsAggregateMutations(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $sku = Sku::fromString('IT-'.strtoupper(bin2hex(random_bytes(4))));
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');

        $repo->add(Product::add($sku, ProductName::fromString('Before'), null, Money::of(1000, 'EUR'), $now));

        $product = $repo->get($sku);
        $product->rename(ProductName::fromString('After'), $now);
        $product->changePrice(Money::of(2000, 'EUR'), $now);
        $product->deactivate($now);
        $repo->save($product);
        $this->em($I)->clear();

        $reloaded = $repo->get($sku);
        $I->assertSame('After', $reloaded->name());
        $I->assertTrue($reloaded->price()->equals(Money::of(2000, 'EUR')));
        $I->assertFalse($reloaded->isActive());
    }

    public function duplicateSkuSurfacesAsDomainException(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $sku = Sku::fromString('IT-'.strtoupper(bin2hex(random_bytes(4))));
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');

        $repo->add(Product::add($sku, ProductName::fromString('First'), null, Money::of(1000, 'EUR'), $now));
        // Two real requests are two units of work: clear the identity map so
        // the second add hits the DB unique index, not Doctrine's in-memory
        // identity collision guard.
        $this->em($I)->clear();

        $I->expectThrowable(DuplicateSku::class, function () use ($repo, $sku, $now): void {
            $repo->add(Product::add($sku, ProductName::fromString('Second'), null, Money::of(1000, 'EUR'), $now));
        });
    }

    public function getUnknownSkuThrowsProductNotFound(IntegrationTester $I): void
    {
        $I->expectThrowable(ProductNotFound::class, function () use ($I): void {
            $this->repo($I)->get(Sku::fromString('NOPE-'.strtoupper(bin2hex(random_bytes(4)))));
        });
    }

    private function repo(IntegrationTester $I): ProductRepository
    {
        return $I->grabService(ProductRepository::class);
    }

    private function em(IntegrationTester $I): EntityManagerInterface
    {
        return $I->grabService(EntityManagerInterface::class);
    }
}
