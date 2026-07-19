<?php

declare(strict_types=1);

namespace App\Notification\Domain;

/**
 * The one value object of a deliberately thin context. Notification has no
 * aggregate and no lifecycle — it turns facts other contexts publish into
 * human-readable messages and hands them to the Mailer port. The recipient
 * is the opaque customer id (customers are an unbuilt fifth context; there
 * is no address book to resolve a real address from).
 */
final class EmailMessage
{
    public const string TYPE_ORDER_CONFIRMATION = 'order_confirmation';
    public const string TYPE_PAYMENT_RECEIPT = 'payment_receipt';
    public const string TYPE_SHIPMENT_NOTICE = 'shipment_notice';

    public function __construct(
        public readonly string $type,
        public readonly string $customerId,
        public readonly string $orderId,
        public readonly string $subject,
        public readonly string $body,
    ) {
        if (!\in_array($type, [self::TYPE_ORDER_CONFIRMATION, self::TYPE_PAYMENT_RECEIPT, self::TYPE_SHIPMENT_NOTICE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown notification type "%s".', $type));
        }
        if (trim($subject) === '' || trim($body) === '') {
            throw new \InvalidArgumentException('An email message needs a non-empty subject and body.');
        }
    }
}
