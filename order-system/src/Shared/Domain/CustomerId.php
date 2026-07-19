<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * An opaque customer identity, taken verbatim from the X-Customer-Id header.
 *
 * Customers are an unbuilt fifth context: no customer table, no profile, no
 * auth (that was part 2's lesson). All four contexts treat this as an opaque
 * value — nobody parses it, nobody validates it against anything.
 */
final class CustomerId implements \Stringable
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > 64) {
            throw new \InvalidArgumentException('Customer id must be 1-64 characters.');
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
