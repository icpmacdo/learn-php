<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\CustomerId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/**
 * DBAL bridge for the CustomerId VO: VARCHAR(64) in MySQL, opaque CustomerId
 * in PHP. There is no customer table to reference — customers are an unbuilt
 * fifth context, and this value is the only thing we know about them.
 */
final class CustomerIdType extends StringType
{
    public const string NAME = 'customer_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?CustomerId
    {
        if ($value === null) {
            return null;
        }
        \assert(\is_string($value));

        return CustomerId::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        \assert($value instanceof CustomerId);

        return $value->value;
    }
}
