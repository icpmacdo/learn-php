<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\CurrencyMismatch;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use Codeception\Test\Unit;

/**
 * Money is the "why value objects" lesson: exact integer arithmetic, currency
 * safety enforced by the type itself, and no float anywhere near it.
 */
final class MoneyTest extends Unit
{
    public function testConstructionHoldsAmountAndCurrency(): void
    {
        $money = Money::of(1999, 'EUR');

        $this->assertSame(1999, $money->amountMinor);
        $this->assertSame('EUR', $money->currency);
    }

    public function testZeroIsAllowed(): void
    {
        $this->assertSame(0, Money::of(0, 'EUR')->amountMinor);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(-1, 'EUR');
    }

    /** @dataProvider invalidCurrencies */
    public function testInvalidCurrencyIsRejected(string $currency): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::of(100, $currency);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCurrencies(): iterable
    {
        yield 'empty' => [''];
        yield 'lowercase' => ['eur'];
        yield 'two letters' => ['EU'];
        yield 'four letters' => ['EURO'];
        yield 'digits' => ['EU1'];
        yield 'symbol' => ['€'];
    }

    public function testAddSumsSameCurrency(): void
    {
        $sum = Money::of(1000, 'EUR')->add(Money::of(500, 'EUR'));

        $this->assertSame(1500, $sum->amountMinor);
        $this->assertSame('EUR', $sum->currency);
    }

    public function testAddIsImmutable(): void
    {
        $original = Money::of(1000, 'EUR');
        $original->add(Money::of(500, 'EUR'));

        $this->assertSame(1000, $original->amountMinor);
    }

    public function testAddingMismatchedCurrenciesThrows(): void
    {
        $this->expectException(CurrencyMismatch::class);
        Money::of(1000, 'EUR')->add(Money::of(1000, 'USD'));
    }

    public function testMultiplyByQuantity(): void
    {
        $total = Money::of(1999, 'EUR')->multiplyBy(Quantity::of(3));

        $this->assertSame(5997, $total->amountMinor);
        $this->assertSame('EUR', $total->currency);
    }

    public function testMultiplyByOneIsIdentityValue(): void
    {
        $money = Money::of(1999, 'EUR');

        $this->assertTrue($money->equals($money->multiplyBy(Quantity::of(1))));
    }

    public function testEquality(): void
    {
        $this->assertTrue(Money::of(100, 'EUR')->equals(Money::of(100, 'EUR')));
        $this->assertFalse(Money::of(100, 'EUR')->equals(Money::of(101, 'EUR')));
        $this->assertFalse(Money::of(100, 'EUR')->equals(Money::of(100, 'USD')));
    }

    public function testComparisonSameCurrency(): void
    {
        $this->assertTrue(Money::of(200, 'EUR')->isGreaterThan(Money::of(100, 'EUR')));
        $this->assertFalse(Money::of(100, 'EUR')->isGreaterThan(Money::of(100, 'EUR')));
        $this->assertFalse(Money::of(50, 'EUR')->isGreaterThan(Money::of(100, 'EUR')));
    }

    public function testComparisonAcrossCurrenciesThrows(): void
    {
        $this->expectException(CurrencyMismatch::class);
        Money::of(200, 'EUR')->isGreaterThan(Money::of(100, 'USD'));
    }

    public function testIsPositive(): void
    {
        $this->assertTrue(Money::of(1, 'EUR')->isPositive());
        $this->assertFalse(Money::of(0, 'EUR')->isPositive());
    }
}
