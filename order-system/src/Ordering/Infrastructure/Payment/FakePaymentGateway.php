<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Payment;

use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\Port\PaymentGateway;
use App\Ordering\Domain\Port\PaymentGatewayTimedOut;
use App\Ordering\Domain\Port\PaymentGatewayUnavailable;
use App\Ordering\Domain\Port\PaymentMethodToken;
use App\Ordering\Domain\Port\PaymentResult;
use App\Ordering\Domain\Port\UnrecognizedPaymentMethod;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Money;
use App\Shared\Domain\UuidV7;
use Doctrine\DBAL\Connection;

/**
 * The faked PSP: behavior selected by TOKEN, so every failure mode is
 * test-controllable over plain HTTP — no backdoors, no test-only endpoints.
 *
 *   tok_success       -> approved, transactionId "fake_<uuid>"
 *   tok_declined      -> declined "insufficient_funds"        (-> 422)
 *   tok_timeout_once  -> FIRST attempt per order throws
 *                        PaymentGatewayTimedOut (-> 502), later
 *                        attempts approve — attempts are PERSISTED in
 *                        ordering_payment_attempt, so the retry works
 *                        across separate HTTP requests
 *   tok_error         -> always throws PaymentGatewayUnavailable (-> 502)
 *   anything else     -> UnrecognizedPaymentMethod              (-> 422)
 *
 * Every attempt (including the failed ones) is recorded BEFORE the outcome
 * is raised, and the PayOrder handler calls charge() outside any DB
 * transaction — so a thrown timeout still leaves its attempt row behind.
 * The attempt table is this adapter's PRIVATE ledger (infra-owned, no
 * domain mapping): swapping in a real PSP deletes it along with this class.
 */
final class FakePaymentGateway implements PaymentGateway
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Clock $clock,
    ) {
    }

    public function charge(OrderId $orderId, Money $amount, PaymentMethodToken $token): PaymentResult
    {
        $priorAttemptsWithToken = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_payment_attempt WHERE order_id = :orderId AND token = :token',
            ['orderId' => $orderId->value, 'token' => $token->value],
        );

        [$outcome, $result] = match ($token->value) {
            'tok_success' => ['approved', PaymentResult::approved('fake_'.UuidV7::generate())],
            'tok_declined' => ['declined', PaymentResult::declined('insufficient_funds')],
            'tok_timeout_once' => $priorAttemptsWithToken === 0
                ? ['timeout', null]
                : ['approved', PaymentResult::approved('fake_'.UuidV7::generate())],
            'tok_error' => ['unavailable', null],
            default => ['unrecognized', null],
        };

        $this->recordAttempt($orderId, $token, $outcome);

        return $result ?? throw match ($outcome) {
            'timeout' => new PaymentGatewayTimedOut('Payment gateway timed out — the charge did not complete; safe to retry.'),
            'unavailable' => new PaymentGatewayUnavailable('Payment gateway is unavailable — the charge did not complete; safe to retry.'),
            default => new UnrecognizedPaymentMethod('Unknown payment method token.'),
        };
    }

    private function recordAttempt(OrderId $orderId, PaymentMethodToken $token, string $outcome): void
    {
        $attemptNo = 1 + (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_payment_attempt WHERE order_id = :orderId',
            ['orderId' => $orderId->value],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO ordering_payment_attempt (order_id, attempt_no, token, outcome, created_at)
                VALUES (:orderId, :attemptNo, :token, :outcome, :createdAt)
                SQL,
            [
                'orderId' => $orderId->value,
                'attemptNo' => $attemptNo,
                'token' => $token->value,
                'outcome' => $outcome,
                'createdAt' => $this->clock->now()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
