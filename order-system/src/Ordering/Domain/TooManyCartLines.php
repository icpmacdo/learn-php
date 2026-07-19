<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/** Invariant C1's ceiling: max 50 distinct lines per cart (-> 422). */
final class TooManyCartLines extends \DomainException
{
}
