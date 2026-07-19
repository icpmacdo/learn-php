<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * Invariant C3: one currency per cart. A 422, not a 409 — the request names
 * a product that can never join THIS cart's lines, there is no state the
 * caller could wait for (short of emptying the cart).
 */
final class CartCurrencyMismatch extends \DomainException
{
}
