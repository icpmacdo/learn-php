<?php

declare(strict_types=1);

namespace App\Notification\Infrastructure\Http;

use App\Shared\Infrastructure\Http\ApiProblem;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-ish read on the notification log (no X-Customer-Id): exists for ops
 * and for acceptance tests to assert "the confirmation/receipt/shipment
 * mails were logged" over plain HTTP, without a database backdoor.
 *
 * Reads notification_log directly — an append-only log queried by its one
 * natural key needs no read model and no repository ceremony.
 */
final class NotificationController
{
    private const string UUID_REQUIREMENT = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    #[Route('/api/notifications', name: 'api_notification_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $orderId = (string) $request->query->get('orderId', '');
        if ($orderId === '') {
            return ApiProblem::validationError('orderId', 'The orderId query parameter is required.');
        }
        if (preg_match(self::UUID_REQUIREMENT, $orderId) !== 1) {
            return ApiProblem::validationError('orderId', 'orderId must be a UUID.');
        }

        /** @var list<array{type: string, customer_id: string, order_id: string, subject: string, created_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT type, customer_id, order_id, subject, created_at
             FROM notification_log WHERE order_id = :orderId ORDER BY id',
            ['orderId' => $orderId],
        );

        return new JsonResponse([
            'notifications' => array_map(
                static fn (array $row): array => [
                    'type' => $row['type'],
                    'customerId' => $row['customer_id'],
                    'orderId' => $row['order_id'],
                    'subject' => $row['subject'],
                    'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(\DateTimeInterface::ATOM),
                ],
                $rows,
            ),
        ]);
    }
}
