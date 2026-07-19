<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/** Checkout on an empty (or never-created) cart -> 422 "Cart is empty.". */
final class EmptyCart extends \DomainException
{
}
