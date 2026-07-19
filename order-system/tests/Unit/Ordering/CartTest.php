<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Cart;
use App\Ordering\Domain\CartCurrencyMismatch;
use App\Ordering\Domain\CartLineNotFound;
use App\Ordering\Domain\TooManyCartLines;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use Codeception\Test\Unit;

/**
 * Cart rules C1-C3. Note what is NOT here: prices. The aggregate cannot even
 * hold one — that absence is invariant C2 enforced by design.
 */
final class CartTest extends Unit
{
    private const string NOW = '2026-07-18T12:00:00+00:00';

    public function testANewCartIsEmptyAndCurrencyAgnostic(): void
    {
        $cart = $this->cart();

        $this->assertTrue($cart->isEmpty());
        $this->assertSame([], $cart->lines());
        $this->assertNull($cart->currency());
    }

    public function testPutLineAddsAndAdoptsTheFirstCurrency(): void
    {
        $cart = $this->cart();
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(2), 'EUR', $this->now());

        $this->assertSame(['WIDGET-1' => 2], $cart->lines());
        $this->assertSame('EUR', $cart->currency());
    }

    public function testPutLineReplacesTheQuantityForTheSameSku(): void
    {
        // C1: PUT semantics — setting a line replaces it, no summing.
        $cart = $this->cart();
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(2), 'EUR', $this->now());
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(7), 'EUR', $this->now());

        $this->assertSame(['WIDGET-1' => 7], $cart->lines());
    }

    public function testMismatchedCurrencyIsRejected(): void
    {
        // C3: one currency per cart, enforced at add time.
        $cart = $this->cart();
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(1), 'EUR', $this->now());

        try {
            $cart->putLine(Sku::fromString('USD-THING'), Quantity::of(1), 'USD', $this->now());
            $this->fail('Expected CartCurrencyMismatch.');
        } catch (CartCurrencyMismatch $e) {
            $this->assertSame('Cart currency mismatch.', $e->getMessage());
        }
        $this->assertSame(['WIDGET-1' => 1], $cart->lines()); // untouched
    }

    public function testEmptyingTheCartForgetsTheCurrency(): void
    {
        $cart = $this->cart();
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(1), 'EUR', $this->now());
        $cart->removeLine(Sku::fromString('WIDGET-1'), $this->now());

        $this->assertNull($cart->currency());
        // ...so a different currency is welcome again:
        $cart->putLine(Sku::fromString('USD-THING'), Quantity::of(1), 'USD', $this->now());
        $this->assertSame('USD', $cart->currency());
    }

    public function testRemovingAMissingLineThrows(): void
    {
        $this->expectException(CartLineNotFound::class);
        $this->cart()->removeLine(Sku::fromString('NOPE-1'), $this->now());
    }

    public function testTheFiftyFirstDistinctLineIsRejected(): void
    {
        // C1: max 50 distinct lines; replacing an existing line stays legal.
        $cart = $this->cart();
        for ($i = 1; $i <= 50; ++$i) {
            $cart->putLine(Sku::fromString('SKU-'.$i), Quantity::of(1), 'EUR', $this->now());
        }

        $cart->putLine(Sku::fromString('SKU-50'), Quantity::of(9), 'EUR', $this->now()); // replace: fine

        $this->expectException(TooManyCartLines::class);
        $cart->putLine(Sku::fromString('SKU-51'), Quantity::of(1), 'EUR', $this->now());
    }

    public function testClearEmptiesEverything(): void
    {
        $cart = $this->cart();
        $cart->putLine(Sku::fromString('WIDGET-1'), Quantity::of(2), 'EUR', $this->now());
        $cart->clear($this->now());

        $this->assertTrue($cart->isEmpty());
        $this->assertNull($cart->currency());
    }

    public function testRestoreRoundTripsStateAndValidatesIt(): void
    {
        $cart = Cart::restore(
            CustomerId::fromString('cust-1'),
            'EUR',
            ['WIDGET-1' => 2, 'GADGET-1' => 3],
            $this->now(),
            $this->now(),
        );

        $this->assertSame(['WIDGET-1' => 2, 'GADGET-1' => 3], $cart->lines());
        $this->assertSame('EUR', $cart->currency());

        $this->expectException(\InvalidArgumentException::class); // garbage rows cannot become a Cart
        Cart::restore(CustomerId::fromString('cust-1'), 'EUR', ['WIDGET-1' => 0], $this->now(), $this->now());
    }

    private function cart(): Cart
    {
        return Cart::forCustomer(CustomerId::fromString('cust-1'), $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
