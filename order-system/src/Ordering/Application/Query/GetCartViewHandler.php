<?php

declare(strict_types=1);

namespace App\Ordering\Application\Query;

use App\Ordering\Domain\CartRepository;
use App\Ordering\Domain\Port\ProductCatalog;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;

/**
 * GET /api/cart: quantities from the cart, names and prices resolved LIVE
 * through the ProductCatalog ACL (C2 — carts store no prices; what you see
 * here is what checkout would freeze right now).
 *
 * Decided edge (see README): a line whose product has meanwhile gone
 * unknown/inactive — or been re-priced into another currency than the
 * cart's — cannot be priced IN THIS CART and is OMITTED from the view (a
 * GET must stay side-effect-free and renderable; summing mixed currencies
 * would throw CurrencyMismatch); checkout, by contrast, refuses loudly
 * (409) — it never silently drops a line the customer asked for. The line
 * stays in the cart and can be DELETEd.
 */
final class GetCartViewHandler
{
    public function __construct(
        private readonly CartRepository $carts,
        private readonly ProductCatalog $catalog,
    ) {
    }

    public function handle(CustomerId $customerId): CartView
    {
        $cart = $this->carts->byCustomerOrNull($customerId);
        if ($cart === null || $cart->isEmpty()) {
            return CartView::empty();
        }

        $lines = [];
        $total = null;
        foreach ($cart->lines() as $skuValue => $quantity) {
            $snapshot = $this->catalog->activeProduct(Sku::fromString($skuValue));
            if ($snapshot === null) {
                continue; // unpriceable line: omitted from the view, still in the cart
            }
            if ($cart->currency() !== null && $snapshot->unitPrice->currency !== $cart->currency()) {
                continue; // re-priced into another currency: same unpriceable edge (checkout 409s)
            }

            $lineTotal = $snapshot->unitPrice->multiplyBy(Quantity::of($quantity));
            $lines[] = new CartLineView($skuValue, $snapshot->name, $snapshot->unitPrice, $quantity, $lineTotal);
            $total = $total === null ? $lineTotal : $total->add($lineTotal);
        }

        return new CartView($lines, $total);
    }
}
