<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Domain\Quantity;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

/**
 * DBAL bridge for the Quantity VO: INT in MySQL, Quantity (1-99) in PHP.
 * Like SkuType, this is how entities hold value objects while staying
 * framework-free — the conversion lives in Infrastructure. (Extends the
 * base Type, not IntegerType, because DBAL pins IntegerType's PHP value
 * to ?int and ours is the VO.).
 */
final class QuantityType extends Type
{
    public const string NAME = 'quantity';

    /** @param array<string, mixed> $column */
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return $platform->getIntegerTypeDeclarationSQL($column);
    }

    public function getBindingType(): ParameterType
    {
        return ParameterType::INTEGER;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Quantity
    {
        if ($value === null) {
            return null;
        }
        \assert(\is_int($value) || \is_string($value));

        return Quantity::of((int) $value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?int
    {
        if ($value === null) {
            return null;
        }
        \assert($value instanceof Quantity);

        return $value->value;
    }
}
