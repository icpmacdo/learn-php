<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\UuidV7;

/**
 * Order identity, minted IN the domain (PRD: "identity is created in the
 * domain, not by DB auto-increment") so a freshly placed Order can put its
 * own id into OrderPlaced before any flush happens.
 *
 * generate() always produces a UUIDv7; fromString() accepts any well-formed
 * UUID — an id arriving over HTTP that we never minted must become a 404
 * ("not found"), not a 500 ("not parseable").
 */
final class OrderId implements \Stringable
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
            throw new \InvalidArgumentException('Order id must be a UUID.');
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
