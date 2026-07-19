<?php

declare(strict_types=1);

namespace App\Ordering\Domain;

/**
 * The gateway answered — with a no. Thrown by the PayOrder handler when the
 * PaymentResult is a decline; the order stays `placed`-and-payable (the PRD's
 * payment decision) and the API answers 422 "Payment was declined.".
 */
final class PaymentWasDeclined extends \DomainException
{
    private string $reason = '';

    public static function withReason(string $reason): self
    {
        $e = new self('Payment was declined.');
        $e->reason = $reason;

        return $e;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
