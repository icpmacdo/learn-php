<?php

declare(strict_types=1);

namespace App\Notification\Application\Subscriber;

use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\Mailer;
use App\Ordering\Domain\Event\OrderShipped;

/**
 * OrderShipped -> shipment notice. The thinnest of the three: the event
 * carries no lines or totals, and the notice needs none.
 */
final class SendShipmentNotice
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {
    }

    public function __invoke(OrderShipped $event): void
    {
        $this->mailer->send(new EmailMessage(
            EmailMessage::TYPE_SHIPMENT_NOTICE,
            $event->customerId,
            $event->orderId,
            sprintf('Your order has shipped — %s', $event->orderId),
            sprintf(
                'Good news: order %s left our warehouse on %s.',
                $event->orderId,
                $event->shippedAt->format('Y-m-d H:i'),
            ),
        ));
    }
}
