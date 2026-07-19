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
 * Application handler: orchestration only. Build VOs, ask the aggregate to
 * create itself, persist through the port, dispatch the recorded events.
 * Plain PHP — no framework imports (deptrac enforces it), no bus library
 * (controllers call handlers directly).
 *
 * Runs inside the TransactionBoundary since stage 3: ProductAdded now has a
 * consumer (the read_product_list projector), and the projection row must
 * commit — or roll back — together with the product itself.
 */
final class AddProductHandler
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    public function handle(AddProduct $command): Product
    {
        return $this->transaction->transactional(function () use ($command): Product {
            $product = Product::add(
                Sku::fromString($command->sku),
                ProductName::fromString($command->name),
                $command->description,
                Money::of($command->priceMinor, $command->currency),
                $this->clock->now(),
            );

            $this->products->add($product);
            $this->events->dispatch(...$product->releaseEvents());

            return $product;
        });
    }
}
