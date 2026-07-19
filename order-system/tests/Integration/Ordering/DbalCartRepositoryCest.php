<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Domain\Cart;
use App\Ordering\Domain\CartRepository;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Exception\DriverException;

/**
 * The DBAL adapter (deliberately not ORM — see the class docblock) proves it
 * round-trips: upsert on save, wholesale line rewrite, restore() validation.
 */
final class DbalCartRepositoryCest
{
    public function unknownCustomerHasNoCart(IntegrationTester $I): void
    {
        $I->assertNull($this->repo($I)->byCustomerOrNull($this->customer()));
    }

    public function roundTripsACartWithLines(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $customerId = $this->customer();
        $now = new \DateTimeImmutable('2026-07-18 12:00:00');

        $cart = Cart::forCustomer($customerId, $now);
        $cart->putLine(Sku::fromString('CART-A'), Quantity::of(2), 'EUR', $now);
        $cart->putLine(Sku::fromString('CART-B'), Quantity::of(5), 'EUR', $now);
        $repo->save($cart);

        $reloaded = $repo->byCustomerOrNull($customerId);
        $I->assertNotNull($reloaded);
        $I->assertSame(['CART-A' => 2, 'CART-B' => 5], $reloaded->lines());
        $I->assertSame('EUR', $reloaded->currency());
        $I->assertSame('2026-07-18 12:00:00', $reloaded->createdAt()->format('Y-m-d H:i:s'));
    }

    public function saveIsAnUpsertAndRewritesLines(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $customerId = $this->customer();
        $now = new \DateTimeImmutable('2026-07-18 12:00:00');

        $cart = Cart::forCustomer($customerId, $now);
        $cart->putLine(Sku::fromString('CART-A'), Quantity::of(2), 'EUR', $now);
        $repo->save($cart);

        $later = new \DateTimeImmutable('2026-07-18 13:00:00');
        $reloaded = $repo->byCustomerOrNull($customerId);
        $I->assertNotNull($reloaded);
        $reloaded->putLine(Sku::fromString('CART-A'), Quantity::of(9), 'EUR', $later); // replace
        $reloaded->putLine(Sku::fromString('CART-C'), Quantity::of(1), 'EUR', $later); // add
        $repo->save($reloaded);

        $final = $repo->byCustomerOrNull($customerId);
        $I->assertNotNull($final);
        $I->assertSame(['CART-A' => 9, 'CART-C' => 1], $final->lines());
        $I->assertSame('2026-07-18 13:00:00', $final->updatedAt()->format('Y-m-d H:i:s'));
    }

    public function anEmptiedCartPersistsAsEmptyAndCurrencyless(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $customerId = $this->customer();
        $now = new \DateTimeImmutable('2026-07-18 12:00:00');

        $cart = Cart::forCustomer($customerId, $now);
        $cart->putLine(Sku::fromString('CART-A'), Quantity::of(2), 'EUR', $now);
        $repo->save($cart);

        $cart->clear($now);
        $repo->save($cart);

        $reloaded = $repo->byCustomerOrNull($customerId);
        $I->assertNotNull($reloaded);
        $I->assertTrue($reloaded->isEmpty());
        $I->assertNull($reloaded->currency());
    }

    public function saveIsAtomicWhenALineInsertFails(IntegrationTester $I): void
    {
        // Regression: save() rewrites lines as DELETE-all + per-line INSERTs;
        // without its own transaction, a failure mid-rewrite COMMITTED the
        // DELETE and silently emptied the cart (the direct PUT/DELETE paths
        // run without a surrounding TransactionBoundary).
        $repo = $this->repo($I);
        $customerId = $this->customer();
        $now = new \DateTimeImmutable('2026-07-18 12:00:00');

        $cart = Cart::forCustomer($customerId, $now);
        $cart->putLine(Sku::fromString('CART-A'), Quantity::of(2), 'EUR', $now);
        $repo->save($cart);

        // The domain cannot mint an unpersistable line (the VOs guarantee
        // rows that fit the schema), so reflection injects one — a 33-char
        // sku overflowing the VARCHAR(32) column — standing in for any
        // infrastructure failure mid-rewrite. It sits FIRST in iteration
        // order, so the failure hits BETWEEN the DELETE and any successful
        // re-INSERT: the worst spot.
        $doomed = Cart::restore($customerId, 'EUR', ['CART-A' => 9], $now, $now);
        (new \ReflectionProperty(Cart::class, 'lines'))
            ->setValue($doomed, [str_repeat('X', 33) => 1, 'CART-A' => 9]);
        $I->expectThrowable(DriverException::class, static function () use ($repo, $doomed): void {
            $repo->save($doomed);
        });

        // The failed rewrite rolled back wholesale — nothing emptied,
        // nothing half-applied:
        $reloaded = $repo->byCustomerOrNull($customerId);
        $I->assertNotNull($reloaded);
        $I->assertSame(['CART-A' => 2], $reloaded->lines());
    }

    private function repo(IntegrationTester $I): CartRepository
    {
        return $I->grabService(CartRepository::class);
    }

    private function customer(): CustomerId
    {
        return CustomerId::fromString('it-cart-'.bin2hex(random_bytes(4)));
    }
}
