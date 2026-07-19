<?php

declare(strict_types=1);

namespace App\Notification\Application\Subscriber;

use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\Mailer;
use App\Notification\Domain\MoneyText;
use App\Ordering\Domain\Event\OrderPlaced;

/**
 * OrderPlaced -> confirmation email. A CONFORMIST consumer of Ordering's
 * published language: everything in the message comes from the event's
 * scalar payload — Notification never reads live order, product or stock
 * state (and the deptrac law would not let it).
 *
 * Registered on the dispatcher in services.yaml; runs synchronously inside
 * the checkout transaction, so a confirmation row exists if and only if the
 * order really was placed.
 */
final class SendOrderConfirmation
{
    public function __construct(
        private readonly Mailer $mailer,
    ) {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $lines = array_map(
            static fn (array $line): string => sprintf(
                '- %d x %s (%s) at %s',
                $line['quantity'],
                $line['name'],
                $line['sku'],
                MoneyText::format($line['unitPriceMinor'], $line['currency']),
            ),
            $event->lines,
        );

        $this->mailer->send(new EmailMessage(
            EmailMessage::TYPE_ORDER_CONFIRMATION,
            $event->customerId,
            $event->orderId,
            sprintf('Order confirmation — %s', $event->orderId),
            sprintf(
                "Thank you for your order %s, placed on %s.\n\n%s\n\nTotal: %s\n\nWe will let you know as soon as it ships.",
                $event->orderId,
                $event->placedAt->format('Y-m-d H:i'),
                implode("\n", $lines),
                MoneyText::format($event->totalMinor, $event->currency),
            ),
        ));
    }
}
