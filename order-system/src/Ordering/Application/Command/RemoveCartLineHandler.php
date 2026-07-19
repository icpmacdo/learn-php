<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\CartLineNotFound;
use App\Ordering\Domain\CartRepository;
use App\Shared\Domain\Clock;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Sku;

final class RemoveCartLineHandler
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly Clock $clock,
    ) {
    }

    /** @throws CartLineNotFound also when the customer has no cart at all */
    public function handle(RemoveCartLine $command): void
    {
        $sku = Sku::fromString($command->sku);
        $cart = $this->carts->byCustomerOrNull(CustomerId::fromString($command->customerId))
            ?? throw CartLineNotFound::forSku($sku);

        $cart->removeLine($sku, $this->clock->now());
        $this->carts->save($cart);
    }
}
