<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

use App\Shared\Domain\UuidV7;

/**
 * Reservation identity, minted in the domain (UUIDv7) like OrderId — but its
 * own type in its own context: Inventory and Ordering share VALUES (the
 * order id crosses as a plain string), never identity types.
 */
final class ReservationId implements \Stringable
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function generate(): self
    {
        return new self(UuidV7::generate());
    }

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (!UuidV7::isWellFormed($normalized)) {
            throw new \InvalidArgumentException('Reservation id must be a UUID.');
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
