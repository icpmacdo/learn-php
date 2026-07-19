<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http\Dto;

use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

final class PutCartLineRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Quantity is required.')]
        #[Assert\Range(notInRangeMessage: 'Quantity must be between {{ min }} and {{ max }}.', min: 1, max: 99)]
        public readonly ?int $quantity,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(JsonBody::optionalInt($body, 'quantity'));
    }
}
