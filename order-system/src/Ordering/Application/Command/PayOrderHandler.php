<?php

declare(strict_types=1);

namespace App\Ordering\Application\Command;

use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\PaymentWasDeclined;
use App\Ordering\Domain\Port\PaymentGateway;
use App\Ordering\Domain\Port\PaymentMethodToken;
use App\Shared\Domain\Clock;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\TransactionBoundary;

/**
 * Take payment for a placed order. The PRD's decision, implemented: EVERY
 * payment failure leaves the order `placed`-and-payable with its stock still
 * reserved — placement never un-happens because a charge went wrong, and
 * retry is a cheap second POST.
 *
 * Sequencing matters here:
 *   - the state guard runs BEFORE the charge (never charge a paid/shipped/
 *     cancelled order),
 *   - the gateway is called OUTSIDE the DB transaction — never hold a
 *     transaction open across an external call, and the fake's persisted
 *     attempt rows must SURVIVE a thrown timeout (tok_timeout_once counts
 *     across separate HTTP requests),
 *   - only an approval opens the transaction, which RE-READS the order
 *     under an exclusive row lock before pay() + flush + OrderPaid. The
 *     pre-charge guard saw a snapshot that a concurrent request (a double-
 *     clicked pay, a cancel racing the charge) may have invalidated while
 *     the gateway call was in flight; without the locked re-read the stale
 *     in-memory entity would flush a transition the aggregate forbids
 *     (e.g. paying an order that just committed `cancelled`) or charge
 *     twice. If the re-check fails AFTER an approved charge, the conflict
 *     surfaces as a 409 and the approved attempt stays in the gateway's
 *     persisted ledger (ordering_payment_attempt) for reconciliation — a
 *     real PSP adapter would void the charge here.
 */
final class PayOrderHandler
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly PaymentGateway $gateway,
        private readonly DomainEventDispatcher $events,
        private readonly TransactionBoundary $transaction,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @throws OrderNotFound                                             also for another customer's order (404, existence hidden)
     * @throws \App\Ordering\Domain\IllegalOrderTransition               not placed (409)
     * @throws \App\Ordering\Domain\Port\UnrecognizedPaymentMethod (422)
     * @throws PaymentWasDeclined                                  (422)
     * @throws \App\Ordering\Domain\Port\PaymentGatewayTimedOut    (502)
     * @throws \App\Ordering\Domain\Port\PaymentGatewayUnavailable (502)
     */
    public function handle(PayOrder $command): Order
    {
        $orderId = OrderId::fromString($command->orderId);
        $order = $this->orders->byIdOrNull($orderId);
        if ($order === null || !$order->customerId()->equals(CustomerId::fromString($command->customerId))) {
            throw OrderNotFound::withId($orderId);
        }

        $order->assertPayable();

        $result = $this->gateway->charge(
            $order->id(),
            $order->total(),
            PaymentMethodToken::fromString($command->paymentMethodToken),
        );

        if (!$result->isApproved()) {
            throw PaymentWasDeclined::withReason($result->declineReason());
        }

        return $this->transaction->transactional(function () use ($orderId, $result): Order {
            // Locked re-read: the world may have changed during the charge.
            // pay() re-asserts payability on CURRENT state — a concurrent
            // pay/cancel that committed meanwhile makes this a 409, never a
            // silently overwritten forbidden transition.
            $order = $this->orders->byIdForUpdate($orderId)
                ?? throw OrderNotFound::withId($orderId);
            $order->pay($result->transactionId(), $this->clock->now());
            $this->orders->save($order);
            $this->events->dispatch(...$order->releaseEvents());

            return $order;
        });
    }
}
