<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Http\Dto;

use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

final class SetStockRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'On-hand quantity is required.')]
        #[Assert\PositiveOrZero(message: 'On-hand quantity cannot be negative.')]
        public readonly ?int $onHand,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(JsonBody::optionalInt($body, 'onHand'));
    }
}
