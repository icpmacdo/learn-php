<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Cart;
use App\Ordering\Domain\CartRepository;
use App\Ordering\Domain\Port\ProductCatalog;
use App\Ordering\Domain\UnknownOrInactiveProduct;
use App\Shared\Domain\Clock;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * Put a cart line (create-or-replace semantics, hence PUT). The product must
 * be currently sellable — resolved through the ProductCatalog ACL, which is
 * also where the cart learns the product's CURRENCY (for C3). It learns no
 * price: carts price nothing (C2).
 */
final class PutCartLineHandler
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly ProductCatalog $catalog,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws UnknownOrInactiveProduct
     * @throws \App\Ordering\Domain\CartCurrencyMismatch
     * @throws \App\Ordering\Domain\TooManyCartLines
     */
    public function handle(PutCartLine $command): void
    {
        $sku = Sku::fromString($command->sku);
        $snapshot = $this->catalog->activeProduct($sku)
            ?? throw UnknownOrInactiveProduct::forSku($sku);

        $customerId = CustomerId::fromString($command->customerId);
        $now = $this->clock->now();
        $cart = $this->carts->byCustomerOrNull($customerId) ?? Cart::forCustomer($customerId, $now);

        $cart->putLine($sku, Quantity::of($command->quantity), $snapshot->unitPrice->currency, $now);
        $this->carts->save($cart);
    }
}
