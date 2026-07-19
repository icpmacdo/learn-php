<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * An amount of money: integer minor units (cents) + ISO 4217 currency code.
 *
 * Why not a float: 0.1 + 0.2 !== 0.3 in IEEE 754. Prices, line totals and
 * order totals are exact quantities; the only safe representation is integer
 * arithmetic in the smallest currency unit. No float ever enters or leaves
 * this class — construction takes an int, JSON carries {"amountMinor": int}.
 *
 * Operations on two Money values require the same currency; mixing currencies
 * throws CurrencyMismatch (there is no exchange rate in this domain).
 */
final class Money
{
    private function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
    ) {
    }

    public static function of(int $amountMinor, string $currency): self
    {
        if ($amountMinor < 0) {
            throw new \InvalidArgumentException('Money amount cannot be negative.');
        }
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new \InvalidArgumentException('Currency must be a three-letter uppercase ISO 4217 code.');
        }

        return new self($amountMinor, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amountMinor + $other->amountMinor, $this->currency);
    }

    public function multiplyBy(Quantity $quantity): self
    {
        return new self($this->amountMinor * $quantity->value, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amountMinor === $other->amountMinor && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amountMinor > $other->amountMinor;
    }

    public function isPositive(): bool
    {
        return $this->amountMinor > 0;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new CurrencyMismatch(sprintf('Cannot operate on %s and %s.', $this->currency, $other->currency));
        }
    }
}
