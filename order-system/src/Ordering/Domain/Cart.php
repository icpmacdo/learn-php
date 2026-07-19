<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * A customer's mutable pre-order. Identity: the CustomerId (one cart per
 * customer). Lines are sku -> quantity ONLY.
 *
 * Invariants (PRD C1-C3):
 *   C1  quantities are Quantity (1-99); putting a line REPLACES it; max 50
 *       distinct lines.
 *   C2  carts store NO prices. Prices and names are resolved live through
 *       the ProductCatalog port at read time and snapshotted only at
 *       checkout — a cart held for a week checks out at today's price; the
 *       ORDER then freezes it. (The alternative, price-at-add-time, silently
 *       sells at stale prices.)
 *   C3  all lines share one currency, remembered from the first line and
 *       cleared when the cart empties; a mismatching add throws
 *       ("Cart currency mismatch." -> 422).
 *
 * Cart emits NO events — nothing consumes them, and an event nobody listens
 * to is ceremony. Not every aggregate publishes.
 */
class Cart
{
    public const int MAX_LINES = 50;

    /**
     * @param array<string, int> $lines sku => quantity
     */
    private function __construct(
        private readonly CustomerId $customerId,
        private ?string $currency,
        private array $lines,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function forCustomer(CustomerId $customerId, \DateTimeImmutable $now): self
    {
        return new self($customerId, null, [], $now, $now);
    }

    /**
     * Rehydration ONLY — the persistence adapter's way back from rows to an
     * aggregate. The Cart is deliberately not ORM-mapped (a mutable scalar
     * map fights the ORM harder than it helps), so this named constructor
     * plays the role Doctrine's reflection hydration plays elsewhere. Inputs
     * are re-validated through the VOs: garbage rows cannot become a Cart.
     *
     * @param array<string, int> $lines sku => quantity
     */
    public static function restore(
        CustomerId $customerId,
        ?string $currency,
        array $lines,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): self {
        $validated = [];
        foreach ($lines as $sku => $quantity) {
            $validated[Sku::fromString($sku)->value] = Quantity::of($quantity)->value;
        }

        return new self($customerId, $currency, $validated, $createdAt, $updatedAt);
    }

    /**
     * Put a line: sets the quantity for the SKU, replacing any previous one.
     *
     * @param string $currency the currency the product is currently priced
     *                         in — the cart only remembers it to enforce C3,
     *                         never a price
     *
     * @throws CartCurrencyMismatch (C3)
     * @throws TooManyCartLines     (C1)
     */
    public function putLine(Sku $sku, Quantity $quantity, string $currency, \DateTimeImmutable $now): void
    {
        if ($this->currency !== null && $currency !== $this->currency) {
            throw new CartCurrencyMismatch('Cart currency mismatch.');
        }
        if (!isset($this->lines[$sku->value]) && \count($this->lines) >= self::MAX_LINES) {
            throw new TooManyCartLines(sprintf('A cart cannot have more than %d distinct lines.', self::MAX_LINES));
        }

        $this->currency ??= $currency;
        $this->lines[$sku->value] = $quantity->value;
        $this->updatedAt = $now;
    }

    /** @throws CartLineNotFound when the SKU is not in the cart */
    public function removeLine(Sku $sku, \DateTimeImmutable $now): void
    {
        if (!isset($this->lines[$sku->value])) {
            throw CartLineNotFound::forSku($sku);
        }

        unset($this->lines[$sku->value]);
        if ($this->lines === []) {
            $this->currency = null; // an empty cart is currency-agnostic again
        }
        $this->updatedAt = $now;
    }

    /** Checkout consumed the cart: empty it and forget the currency. */
    public function clear(\DateTimeImmutable $now): void
    {
        $this->lines = [];
        $this->currency = null;
        $this->updatedAt = $now;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /** @return array<string, int> sku => quantity */
    public function lines(): array
    {
        return $this->lines;
    }

    public function currency(): ?string
    {
        return $this->currency;
    }

    public function customerId(): CustomerId
    {
        return $this->customerId;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
