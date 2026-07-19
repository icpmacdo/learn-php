<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Doctrine;

use App\Ordering\Domain\OrderId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/**
 * DBAL bridge for the OrderId VO: VARCHAR(36) in MySQL, OrderId in PHP.
 * Lives in Ordering's Infrastructure (not Shared) because OrderId is an
 * Ordering-owned type — Shared Infrastructure may not import it.
 */
final class OrderIdType extends StringType
{
    public const string NAME = 'order_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?OrderId
    {
        if ($value === null) {
            return null;
        }
        \assert(\is_string($value));

        return OrderId::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        \assert($value instanceof OrderId);

        return $value->value;
    }
}
