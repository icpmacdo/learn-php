<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /api/tokens body -- the credential-for-token
 * exchange. `name` is a client-supplied label ("laptop curl") so a user can
 * tell their tokens apart when listing/revoking.
 */
final class IssueTokenRequest
{
    public function __construct(
        #[Assert\NotBlank]
        public readonly string $email,
        #[Assert\NotBlank]
        public readonly string $password,
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public readonly string $name,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            JsonBody::string($body, 'email'),
            JsonBody::string($body, 'password'),
            JsonBody::string($body, 'name'),
        );
    }
}
