<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure;

use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\Mailer;
use App\Shared\Domain\Clock;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * The Mailer adapter: logged, never sent. Twice, on purpose:
 *
 *  - a notification_log ROW — the queryable record behind
 *    GET /api/notifications, which is what acceptance tests assert against;
 *  - a PSR-3 line on the dedicated "notification" monolog channel, so
 *    `grep notification var/log/dev.log` (or the standalone
 *    var/log/notification.log) shows the mail traffic at a glance.
 *
 * The row insert rides the surrounding command transaction (same DBAL
 * connection): a confirmation is recorded if and only if the order commit
 * actually happened. $notificationLogger is autowired by argument name to
 * the "notification" channel declared in config/packages/monolog.yaml.
 */
final class LoggingMailer implements Mailer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $notificationLogger,
        private readonly Clock $clock,
    ) {
    }

    public function send(EmailMessage $message): void
    {
        $this->connection->insert('notification_log', [
            'type' => $message->type,
            'customer_id' => $message->customerId,
            'order_id' => $message->orderId,
            'subject' => $message->subject,
            'body' => $message->body,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);

        $this->notificationLogger->info(
            sprintf('EMAIL (%s) to customer %s: %s', $message->type, $message->customerId, $message->subject),
            [
                'type' => $message->type,
                'customerId' => $message->customerId,
                'orderId' => $message->orderId,
                'body' => $message->body,
            ],
        );
    }
}
