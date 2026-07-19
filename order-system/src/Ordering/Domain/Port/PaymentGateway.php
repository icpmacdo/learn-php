<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

use App\Ordering\Domain\OrderId;
use App\Shared\Domain\Money;

/**
 * Port: charge the customer's payment method for an order.
 *
 * This is the hexagon's showpiece seam: the domain defines the contract
 * (result shape, failure modes), Infrastructure provides FakePaymentGateway,
 * and swapping in a real PSP adapter one day touches ONLY
 * Ordering/Infrastructure — the spec's hexagonal claim, demonstrable.
 *
 * Failure modes are part of the contract because real gateways have them:
 * a decline is an ANSWER (a PaymentResult), while a timeout or outage is a
 * NON-answer (an exception) — the caller must treat "I don't know whether
 * money moved" differently from "no".
 */
interface PaymentGateway
{
    /**
     * @throws PaymentGatewayTimedOut    the charge may or may not have
     *                                   reached the processor; safe to
     *                                   retry here because the fake never
     *                                   half-commits
     * @throws PaymentGatewayUnavailable the gateway is down; nothing moved
     * @throws UnrecognizedPaymentMethod the token itself is not chargeable
     */
    public function charge(OrderId $orderId, Money $amount, PaymentMethodToken $token): PaymentResult;
}
