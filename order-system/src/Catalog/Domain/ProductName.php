<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * A product's display name: 1-255 characters, trimmed. Catalog-owned — when
 * an order is placed, Ordering snapshots the *string*, not this VO, because
 * a renamed product must never rewrite a past order's lines.
 */
final class ProductName implements \Stringable
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > 255) {
            throw new \InvalidArgumentException('Product name must be 1-255 characters.');
        }

        return new self($trimmed);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
