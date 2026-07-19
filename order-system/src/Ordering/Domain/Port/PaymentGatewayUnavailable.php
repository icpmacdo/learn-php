<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

/**
 * The gateway is down: no charge happened. Surfaces as 502; the order stays
 * `placed`-and-payable.
 */
final class PaymentGatewayUnavailable extends \RuntimeException
{
}
