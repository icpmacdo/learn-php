<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Port\PaymentResult;
use Codeception\Test\Unit;

/** The result type refuses to be read against its branch — no transaction ids off declines. */
final class PaymentResultTest extends Unit
{
    public function testApprovedCarriesATransactionId(): void
    {
        $result = PaymentResult::approved('fake_abc');

        $this->assertTrue($result->isApproved());
        $this->assertSame('fake_abc', $result->transactionId());
    }

    public function testDeclinedCarriesAReason(): void
    {
        $result = PaymentResult::declined('insufficient_funds');

        $this->assertFalse($result->isApproved());
        $this->assertSame('insufficient_funds', $result->declineReason());
    }

    public function testReadingTransactionIdOffADeclineThrows(): void
    {
        $this->expectException(\LogicException::class);
        PaymentResult::declined('insufficient_funds')->transactionId();
    }

    public function testReadingDeclineReasonOffAnApprovalThrows(): void
    {
        $this->expectException(\LogicException::class);
        PaymentResult::approved('fake_abc')->declineReason();
    }
}
