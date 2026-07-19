<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

/**
 * The gateway's answer: approved (with the processor's transaction id) or
 * declined (with a reason). Accessors throw on the wrong branch so a caller
 * can never read a transaction id off a decline.
 */
final class PaymentResult
{
    private function __construct(
        private readonly bool $approved,
        private readonly ?string $transactionId,
        private readonly ?string $declineReason,
    ) {
    }

    public static function approved(string $transactionId): self
    {
        return new self(true, $transactionId, null);
    }

    public static function declined(string $reason): self
    {
        return new self(false, null, $reason);
    }

    public function isApproved(): bool
    {
        return $this->approved;
    }

    public function transactionId(): string
    {
        if (!$this->approved || $this->transactionId === null) {
            throw new \LogicException('A declined payment has no transaction id.');
        }

        return $this->transactionId;
    }

    public function declineReason(): string
    {
        if ($this->approved || $this->declineReason === null) {
            throw new \LogicException('An approved payment has no decline reason.');
        }

        return $this->declineReason;
    }
}
