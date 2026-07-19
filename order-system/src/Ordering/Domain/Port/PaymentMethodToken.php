<?php

declare(strict_types=1);

namespace App\Ordering\Domain\Port;

/**
 * An opaque payment-method token, as a real PSP would hand out ("tok_...").
 * The domain validates only the SHAPE; whether a token is chargeable is the
 * gateway's call (the fake rejects anything outside its table with
 * UnrecognizedPaymentMethod) — token semantics belong behind the port.
 */
final class PaymentMethodToken
{
    private function __construct(
        public readonly string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > 64 || preg_match('/\s/', $trimmed) === 1) {
            throw new \InvalidArgumentException('Payment method token must be 1-64 non-whitespace characters.');
        }

        return new self($trimmed);
    }
}
