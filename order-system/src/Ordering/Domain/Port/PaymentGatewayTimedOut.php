<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

/**
 * The gateway did not answer in time — the charge may or may not have
 * completed on the other side. Surfaces as 502; the order stays
 * `placed`-and-payable so retrying is cheap (the PRD's payment decision).
 */
final class PaymentGatewayTimedOut extends \RuntimeException
{
}
