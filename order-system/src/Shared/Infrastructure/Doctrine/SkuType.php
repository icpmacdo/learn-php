<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Sku;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/**
 * DBAL bridge for the Sku VO: VARCHAR(32) in MySQL, Sku object in PHP.
 *
 * This class is why entities can hold value objects while staying
 * framework-free: the conversion knowledge lives here, in Infrastructure,
 * registered in config/packages/doctrine.yaml — the domain never learns that
 * databases exist.
 */
final class SkuType extends StringType
{
    public const string NAME = 'sku';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Sku
    {
        if ($value === null) {
            return null;
        }
        \assert(\is_string($value));

        return Sku::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        \assert($value instanceof Sku);

        return $value->value;
    }
}
