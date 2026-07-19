<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

/**
 * The token is well-formed but names no chargeable payment method. A 422
 * (the request itself is bad), not a decline — no charge was ever attempted.
 */
final class UnrecognizedPaymentMethod extends \DomainException
{
}
