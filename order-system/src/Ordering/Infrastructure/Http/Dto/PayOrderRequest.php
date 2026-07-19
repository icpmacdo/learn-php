<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Dto;

use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

final class PayOrderRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Payment method token is required.')]
        #[Assert\Length(max: 64, maxMessage: 'Payment method token must be at most {{ limit }} characters.')]
        // NotBlank passes whitespace-only strings; without this the domain's
        // PaymentMethodToken shape guard would throw past the controller (500).
        #[Assert\Regex(pattern: '/^\S+$/', message: 'Payment method token must not contain whitespace.')]
        public readonly string $paymentMethodToken,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(JsonBody::string($body, 'paymentMethodToken'));
    }
}
