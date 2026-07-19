<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Stock-keeping unit — the identity handshake between every context.
 *
 * Catalog's Product, Inventory's StockItem and Ordering's OrderLine are three
 * different models of "a thing we sell"; the SKU is the only fact they agree
 * on, which is why it (and only it) lives in the shared kernel.
 *
 * Normalized to uppercase in the constructor so "abc-1" and "ABC-1" are the
 * same identity everywhere.
 */
final class Sku implements \Stringable
{
    private const string PATTERN = '/^[A-Z0-9-]{3,32}$/';

    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw new \InvalidArgumentException('SKU must be 3-32 characters of A-Z, 0-9 or "-".');
        }

        return new self($normalized);
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
