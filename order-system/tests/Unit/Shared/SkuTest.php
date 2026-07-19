<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\Sku;
use Codeception\Test\Unit;

final class SkuTest extends Unit
{
    /** @dataProvider validSkus */
    public function testValidSkusAreAcceptedAndNormalized(string $input, string $expected): void
    {
        $this->assertSame($expected, Sku::fromString($input)->value);
    }

    /** @return iterable<string, array{string, string}> */
    public static function validSkus(): iterable
    {
        yield 'plain' => ['ABC', 'ABC'];
        yield 'lowercase normalized' => ['widget-1', 'WIDGET-1'];
        yield 'whitespace trimmed' => ['  ABC-123  ', 'ABC-123'];
        yield 'digits only' => ['12345', '12345'];
        yield 'max length (32)' => [str_repeat('A', 32), str_repeat('A', 32)];
    }

    /** @dataProvider invalidSkus */
    public function testInvalidSkusAreRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Sku::fromString($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSkus(): iterable
    {
        yield 'empty' => [''];
        yield 'too short (2)' => ['AB'];
        yield 'too long (33)' => [str_repeat('A', 33)];
        yield 'underscore' => ['ABC_1'];
        yield 'space inside' => ['AB C'];
        yield 'dot' => ['ABC.1'];
        yield 'unicode' => ['ÄBC'];
    }

    public function testEqualityIsByNormalizedValue(): void
    {
        $this->assertTrue(Sku::fromString('abc-1')->equals(Sku::fromString('ABC-1')));
        $this->assertFalse(Sku::fromString('ABC-1')->equals(Sku::fromString('ABC-2')));
    }

    public function testStringable(): void
    {
        $this->assertSame('ABC-1', (string) Sku::fromString('abc-1'));
    }
}
