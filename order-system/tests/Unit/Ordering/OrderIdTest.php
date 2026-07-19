<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\OrderId;
use Codeception\Test\Unit;

final class OrderIdTest extends Unit
{
    public function testGenerateMintsAVersion7Uuid(): void
    {
        $id = OrderId::generate();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id->value,
        );
    }

    public function testGeneratedIdsAreUniqueAndTimeOrdered(): void
    {
        $first = OrderId::generate();
        usleep(2000); // v7's leading 48 bits are unix milliseconds
        $second = OrderId::generate();

        $this->assertNotSame($first->value, $second->value);
        $this->assertLessThan($second->value, $first->value); // lexicographic = chronological
    }

    public function testFromStringNormalizesCase(): void
    {
        $id = OrderId::fromString('019F77BF-19A6-71AB-B125-5047CD5321E1');

        $this->assertSame('019f77bf-19a6-71ab-b125-5047cd5321e1', $id->value);
    }

    public function testFromStringAcceptsAnyWellFormedUuid(): void
    {
        // A v4 we never minted must be parseable (it becomes a 404, not a 500).
        $this->assertSame(
            '123e4567-e89b-42d3-a456-426614174000',
            OrderId::fromString('123e4567-e89b-42d3-a456-426614174000')->value,
        );
    }

    /** @dataProvider malformedIds */
    public function testMalformedIdsAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OrderId::fromString($value);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIds(): iterable
    {
        yield 'empty' => [''];
        yield 'not a uuid' => ['order-42'];
        yield 'missing group' => ['019f77bf-19a6-71ab-b125'];
        yield 'bad characters' => ['019f77bf-19a6-71ab-b125-5047cd5321zz'];
    }

    public function testEquality(): void
    {
        $a = OrderId::fromString('019f77bf-19a6-71ab-b125-5047cd5321e1');
        $b = OrderId::fromString('019F77BF-19A6-71AB-B125-5047CD5321E1');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(OrderId::generate()));
    }
}
