<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Doctrine;

use App\Inventory\Domain\ReservationId;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/**
 * DBAL bridge for the ReservationId VO: VARCHAR(36) in MySQL, ReservationId
 * in PHP. Inventory-owned, so it lives in Inventory's Infrastructure.
 */
final class ReservationIdType extends StringType
{
    public const string NAME = 'reservation_id';

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?ReservationId
    {
        if ($value === null) {
            return null;
        }
        \assert(\is_string($value));

        return ReservationId::fromString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }
        \assert($value instanceof ReservationId);

        return $value->value;
    }
}
