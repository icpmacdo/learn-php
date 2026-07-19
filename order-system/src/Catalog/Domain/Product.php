<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

use App\Catalog\Domain\Event\ProductAdded;
use App\Catalog\Domain\Event\ProductDeactivated;
use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductReactivated;
use App\Catalog\Domain\Event\ProductRenamed;
use App\Shared\Domain\Money;
use App\Shared\Domain\RecordsEvents;
use App\Shared\Domain\Sku;

/**
 * Catalog's model of "a thing we sell": merchandising truth. Knows its SKU,
 * name, description, price and whether it is sellable — deliberately knows
 * nothing about stock levels (Inventory's problem) or who ordered it
 * (Ordering's problem).
 *
 * Invariants (PRD P1-P3):
 *   P1  price is Money with amount > 0 — enforced in add() and changePrice().
 *   P2  the SKU is the identity and is immutable — there is no setter, and
 *       uniqueness is a DB unique index surfaced as DuplicateSku (no racy
 *       pre-check).
 *   P3  inactive products are unsellable — enforced at the Ordering side via
 *       the ACL, which only ever serves active products.
 *
 * Pure PHP: no Doctrine attributes (mapping lives in
 * config/doctrine/Catalog/Product.orm.xml), no framework imports — deptrac
 * turns red otherwise. All mutation goes through intention-revealing methods
 * that record domain events; there are no setters.
 */
class Product
{
    use RecordsEvents;

    private Sku $sku;
    private string $name;
    private ?string $description;
    private Money $price;
    private bool $active;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    private function __construct(
        Sku $sku,
        ProductName $name,
        ?string $description,
        Money $price,
        \DateTimeImmutable $now,
    ) {
        self::assertValidPrice($price);

        $this->sku = $sku;
        $this->name = $name->value;
        $this->description = $description;
        $this->price = $price;
        $this->active = true;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public static function add(
        Sku $sku,
        ProductName $name,
        ?string $description,
        Money $price,
        \DateTimeImmutable $now,
    ): self {
        $product = new self($sku, $name, $description, $price, $now);
        $product->recordThat(new ProductAdded($sku, $name->value, $price));

        return $product;
    }

    public function rename(ProductName $name, \DateTimeImmutable $now): void
    {
        if ($this->name === $name->value) {
            return; // No change, no event: events are facts, not echoes.
        }

        $this->name = $name->value;
        $this->touch($now);
        $this->recordThat(new ProductRenamed($this->sku, $this->name));
    }

    public function changePrice(Money $price, \DateTimeImmutable $now): void
    {
        self::assertValidPrice($price);

        if ($this->price->equals($price)) {
            return; // No change, no event: events are facts, not echoes.
        }

        $this->price = $price;
        $this->touch($now);
        $this->recordThat(new ProductPriceChanged($this->sku, $price));
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        if (!$this->active) {
            return;
        }

        $this->active = false;
        $this->touch($now);
        $this->recordThat(new ProductDeactivated($this->sku));
    }

    public function reactivate(\DateTimeImmutable $now): void
    {
        if ($this->active) {
            return;
        }

        $this->active = true;
        $this->touch($now);
        $this->recordThat(new ProductReactivated($this->sku));
    }

    public function sku(): Sku
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    private static function assertValidPrice(Money $price): void
    {
        if (!$price->isPositive()) {
            throw new InvalidProductPrice('Product price must be greater than zero.');
        }
    }
}
