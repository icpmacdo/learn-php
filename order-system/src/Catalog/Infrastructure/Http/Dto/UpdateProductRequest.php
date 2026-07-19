<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http\Dto;

use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /api/products/{sku}: every field optional, but priceMinor and
 * currency travel as a pair — changing a price without saying in which
 * currency (or vice versa) is rejected by the Callback below.
 */
final class UpdateProductRequest
{
    public function __construct(
        #[Assert\Length(min: 1, max: 255)]
        public readonly ?string $name,
        #[Assert\Positive(message: 'Price must be a positive amount in minor units.')]
        public readonly ?int $priceMinor,
        #[Assert\Currency(message: 'Currency must be a valid ISO 4217 code.')]
        public readonly ?string $currency,
        public readonly ?bool $active,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $currency = JsonBody::optionalString($body, 'currency');

        return new self(
            JsonBody::optionalString($body, 'name'),
            JsonBody::optionalInt($body, 'priceMinor'),
            $currency === null ? null : strtoupper($currency),
            JsonBody::optionalBool($body, 'active'),
        );
    }

    #[Assert\Callback]
    public function validatePricePair(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (($this->priceMinor === null) !== ($this->currency === null)) {
            $context->buildViolation('priceMinor and currency must be provided together.')
                ->atPath('priceMinor')
                ->addViolation();
        }
    }
}
