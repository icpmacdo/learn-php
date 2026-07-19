<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\Port\PaymentGateway;
use App\Ordering\Domain\Port\PaymentGatewayTimedOut;
use App\Ordering\Domain\Port\PaymentGatewayUnavailable;
use App\Ordering\Domain\Port\PaymentMethodToken;
use App\Ordering\Domain\Port\UnrecognizedPaymentMethod;
use App\Shared\Domain\Money;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The fake per-token contract, including its one piece of real persistence:
 * attempts are rows, so tok_timeout_once works across separate requests.
 * Grabbed through the PORT — the tests couldn't care less which adapter
 * answers (they'd fail loudly against a real one, which is the point of the
 * token table being part of the contract).
 */
final class FakePaymentGatewayCest
{
    public function tokSuccessApprovesWithAFakeTransactionId(IntegrationTester $I): void
    {
        $result = $this->gateway($I)->charge(OrderId::generate(), $this->amount(), $this->token('tok_success'));

        $I->assertTrue($result->isApproved());
        $I->assertStringStartsWith('fake_', $result->transactionId());
    }

    public function tokDeclinedDeclinesWithInsufficientFunds(IntegrationTester $I): void
    {
        $result = $this->gateway($I)->charge(OrderId::generate(), $this->amount(), $this->token('tok_declined'));

        $I->assertFalse($result->isApproved());
        $I->assertSame('insufficient_funds', $result->declineReason());
    }

    public function tokTimeoutOnceTimesOutFirstThenApproves(IntegrationTester $I): void
    {
        $gateway = $this->gateway($I);
        $orderId = OrderId::generate();

        $I->expectThrowable(PaymentGatewayTimedOut::class, function () use ($gateway, $orderId): void {
            $gateway->charge($orderId, $this->amount(), $this->token('tok_timeout_once'));
        });

        // The attempt SURVIVED the exception (persisted ledger)...
        $I->assertSame(1, $this->attemptCount($I, $orderId));

        // ...so the second attempt for the SAME order approves:
        $retry = $gateway->charge($orderId, $this->amount(), $this->token('tok_timeout_once'));
        $I->assertTrue($retry->isApproved());
        $I->assertSame(2, $this->attemptCount($I, $orderId));

        // ...while a DIFFERENT order starts its own count and times out:
        $I->expectThrowable(PaymentGatewayTimedOut::class, function () use ($gateway): void {
            $gateway->charge(OrderId::generate(), $this->amount(), $this->token('tok_timeout_once'));
        });
    }

    public function tokErrorAlwaysThrowsUnavailable(IntegrationTester $I): void
    {
        $gateway = $this->gateway($I);
        $orderId = OrderId::generate();

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $I->expectThrowable(PaymentGatewayUnavailable::class, function () use ($gateway, $orderId): void {
                $gateway->charge($orderId, $this->amount(), $this->token('tok_error'));
            });
        }
        $I->assertSame(2, $this->attemptCount($I, $orderId)); // no retry forgiveness
    }

    public function unknownTokensAreUnrecognized(IntegrationTester $I): void
    {
        $I->expectThrowable(UnrecognizedPaymentMethod::class, function () use ($I): void {
            $this->gateway($I)->charge(OrderId::generate(), $this->amount(), $this->token('tok_visa'));
        });
    }

    public function attemptRowsRecordTokenOutcomeAndSequence(IntegrationTester $I): void
    {
        $gateway = $this->gateway($I);
        $orderId = OrderId::generate();

        $gateway->charge($orderId, $this->amount(), $this->token('tok_declined'));
        $gateway->charge($orderId, $this->amount(), $this->token('tok_success'));

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT attempt_no, token, outcome FROM ordering_payment_attempt WHERE order_id = :o ORDER BY attempt_no',
            ['o' => $orderId->value],
        );
        $I->assertSame([
            ['attempt_no' => 1, 'token' => 'tok_declined', 'outcome' => 'declined'],
            ['attempt_no' => 2, 'token' => 'tok_success', 'outcome' => 'approved'],
        ], array_map(static fn (array $row): array => [
            'attempt_no' => (int) $row['attempt_no'],
            'token' => (string) $row['token'],
            'outcome' => (string) $row['outcome'],
        ], $rows));
    }

    private function gateway(IntegrationTester $I): PaymentGateway
    {
        return $I->grabService(PaymentGateway::class);
    }

    private function connection(IntegrationTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }

    private function attemptCount(IntegrationTester $I, OrderId $orderId): int
    {
        return (int) $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM ordering_payment_attempt WHERE order_id = :o',
            ['o' => $orderId->value],
        );
    }

    private function amount(): Money
    {
        return Money::of(1000, 'EUR');
    }

    private function token(string $value): PaymentMethodToken
    {
        return PaymentMethodToken::fromString($value);
    }
}
