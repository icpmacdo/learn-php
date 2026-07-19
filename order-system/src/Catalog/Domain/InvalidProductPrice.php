<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Invariant P1 violated: a product price must be Money > 0. The HTTP edge
 * normally catches this earlier via DTO validation; this is the aggregate's
 * own last line of defence.
 */
final class InvalidProductPrice extends \DomainException
{
}
