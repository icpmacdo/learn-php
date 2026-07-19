<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\Quantity;
use Codeception\Test\Unit;

final class QuantityTest extends Unit
{
    /** @dataProvider validQuantities */
    public function testValidRange(int $value): void
    {
        $this->assertSame($value, Quantity::of($value)->value);
    }

    /** @return iterable<string, array{int}> */
    public static function validQuantities(): iterable
    {
        yield 'minimum (1)' => [1];
        yield 'middle' => [50];
        yield 'maximum (99)' => [99];
    }

    /** @dataProvider invalidQuantities */
    public function testOutOfRangeIsRejected(int $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Quantity::of($value);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidQuantities(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'just above max (100)' => [100];
        yield 'far above max' => [1000];
    }

    public function testEquality(): void
    {
        $this->assertTrue(Quantity::of(5)->equals(Quantity::of(5)));
        $this->assertFalse(Quantity::of(5)->equals(Quantity::of(6)));
    }
}
