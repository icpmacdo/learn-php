<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\CustomerId;
use Codeception\Test\Unit;

final class CustomerIdTest extends Unit
{
    public function testHoldsTrimmedOpaqueValue(): void
    {
        $this->assertSame('cust-42', CustomerId::fromString('  cust-42  ')->value);
    }

    public function testMaxLength64IsAccepted(): void
    {
        $value = str_repeat('a', 64);

        $this->assertSame($value, CustomerId::fromString($value)->value);
    }

    /** @dataProvider invalidIds */
    public function testInvalidIdsAreRejected(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CustomerId::fromString($input);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'whitespace only' => ['   '];
        yield 'too long (65)' => [str_repeat('a', 65)];
    }

    public function testEquality(): void
    {
        $this->assertTrue(CustomerId::fromString('alice')->equals(CustomerId::fromString('alice')));
        $this->assertFalse(CustomerId::fromString('alice')->equals(CustomerId::fromString('bob')));
    }
}
