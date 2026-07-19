<?php

declare(strict_types=1);

namespace App\Notification\Application\Subscriber;

use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\Mailer;
use App\Notification\Domain\MoneyText;
use App\Ordering\Domain\Event\OrderPaid;

/**
 * OrderPaid -> payment receipt, composed purely from the event payload
 * (including the gateway's transaction id — the customer's proof of charge).
 */
final class SendPaymentReceipt
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $this->mailer->send(new EmailMessage(
            EmailMessage::TYPE_PAYMENT_RECEIPT,
            $event->customerId,
            $event->orderId,
            sprintf('Payment receipt — %s', $event->orderId),
            sprintf(
                "We received your payment of %s for order %s on %s.\n\nTransaction reference: %s",
                MoneyText::format($event->totalMinor, $event->currency),
                $event->orderId,
                $event->paidAt->format('Y-m-d H:i'),
                $event->transactionId,
            ),
        ));
    }
}
