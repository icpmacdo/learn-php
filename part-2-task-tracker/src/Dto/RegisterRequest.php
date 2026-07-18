<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /api/register body.
 *
 * Email UNIQUENESS is deliberately not validated here: the unique index is
 * the source of truth, and the controller surfaces its violation as a 422
 * (no racy pre-check SELECT -- part 1's code-collision lesson).
 */
final class RegisterRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly string $email,
        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 64)]
        public readonly string $password,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public readonly string $displayName,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            JsonBody::string($body, 'email'),
            JsonBody::string($body, 'password'),
            JsonBody::string($body, 'displayName'),
        );
    }
}
