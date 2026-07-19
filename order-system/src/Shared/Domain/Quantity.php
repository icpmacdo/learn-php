<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * A count of units on a cart or order line: an int from 1 to 99.
 *
 * Zero is not a quantity (removing a line is a separate operation) and 99 is
 * the business's per-line ceiling. Making this a VO instead of a bare int
 * means "quantity ≤ 0" bugs are unrepresentable past the constructor.
 */
final class Quantity
{
    public const int MIN = 1;
    public const int MAX = 99;

    private function __construct(
        public readonly int $value,
    ) {
    }

    public static function of(int $value): self
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new \InvalidArgumentException(sprintf('Quantity must be between %d and %d.', self::MIN, self::MAX));
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
