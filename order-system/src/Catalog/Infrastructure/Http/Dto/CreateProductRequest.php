<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Http\Dto;

use App\Shared\Infrastructure\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /api/products body. Validation at the edge
 * mirrors the domain guards (Sku pattern, positive price) so bad input gets
 * a per-field 422 before any VO constructor throws.
 */
final class CreateProductRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex(pattern: '/^[A-Za-z0-9-]{3,32}$/', message: 'SKU must be 3-32 characters of A-Z, 0-9 or "-".')]
        public readonly string $sku,
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $name,
        #[Assert\Length(max: 10000)]
        public readonly ?string $description,
        #[Assert\NotNull(message: 'Price (minor units) is required.')]
        #[Assert\Positive(message: 'Price must be a positive amount in minor units.')]
        public readonly ?int $priceMinor,
        #[Assert\NotBlank]
        #[Assert\Currency(message: 'Currency must be a valid ISO 4217 code.')]
        public readonly string $currency,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            JsonBody::string($body, 'sku'),
            JsonBody::string($body, 'name'),
            JsonBody::optionalString($body, 'description'),
            JsonBody::optionalInt($body, 'priceMinor'),
            strtoupper(JsonBody::string($body, 'currency')),
        );
    }
}
