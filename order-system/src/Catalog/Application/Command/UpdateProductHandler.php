<?php

declare(strict_types=1);

namespace App\Catalog\Application\Command;

use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductName;
use App\Catalog\Domain\ProductRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;
use App\Shared\Domain\TransactionBoundary;

/**
 * Transactional since stage 3: price/active changes feed the
 * read_product_list projector, and the row must move with the write model.
 */
final class UpdateProductHandler
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /** @throws \App\Catalog\Domain\ProductNotFound */
    public function handle(UpdateProduct $command): Product
    {
        return $this->transaction->transactional(function () use ($command): Product {
            $product = $this->products->get(Sku::fromString($command->sku));
            $now = $this->clock->now();

            if ($command->name !== null) {
                $product->rename(ProductName::fromString($command->name), $now);
            }
            if ($command->priceMinor !== null && $command->currency !== null) {
                $product->changePrice(Money::of($command->priceMinor, $command->currency), $now);
            }
            if ($command->active === true) {
                $product->reactivate($now);
            } elseif ($command->active === false) {
                $product->deactivate($now);
            }

            $this->products->save($product);
            $this->events->dispatch(...$product->releaseEvents());

            return $product;
        });
    }
}
