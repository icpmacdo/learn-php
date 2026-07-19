<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Domain\UuidV7;
use Codeception\Test\Unit;

final class UuidV7Test extends Unit
{
    public function testGenerateProducesVersion7VariantRfcUuids(): void
    {
        for ($i = 0; $i < 50; ++$i) {
            $uuid = UuidV7::generate();
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                $uuid,
            );
        }
    }

    public function testGeneratedUuidsAreUnique(): void
    {
        $uuids = [];
        for ($i = 0; $i < 1000; ++$i) {
            $uuids[UuidV7::generate()] = true;
        }

        $this->assertCount(1000, $uuids);
    }

    public function testTimestampPrefixIsChronological(): void
    {
        $first = UuidV7::generate();
        usleep(2000);
        $second = UuidV7::generate();

        $this->assertLessThan($second, $first);
    }

    public function testIsWellFormedAcceptsAnyRfcShape(): void
    {
        $this->assertTrue(UuidV7::isWellFormed('123e4567-e89b-42d3-a456-426614174000')); // a v4
        $this->assertTrue(UuidV7::isWellFormed(UuidV7::generate()));
        $this->assertFalse(UuidV7::isWellFormed('not-a-uuid'));
        $this->assertFalse(UuidV7::isWellFormed(''));
        $this->assertFalse(UuidV7::isWellFormed('123E4567-E89B-42D3-A456-426614174000')); // uppercase: normalize first
    }
}
