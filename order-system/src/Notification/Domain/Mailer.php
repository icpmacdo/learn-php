<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * Port: deliver a composed message. Part 3's only adapter is LoggingMailer
 * (Infrastructure), which writes a notification_log row and a log line —
 * logged, never sent. A real SMTP/provider adapter would slot in behind this
 * same interface without touching the subscribers that compose messages.
 */
interface Mailer
{
    public function send(EmailMessage $message): void;
}
