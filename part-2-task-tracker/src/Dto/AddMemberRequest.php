<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /api/teams/{id}/members body: which
 * registered user to add (by email) and with which role.
 */
final class AddMemberRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public readonly string $email,
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['member', 'admin'], message: 'Role must be "member" or "admin".')]
        public readonly string $role,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(
            JsonBody::string($body, 'email'),
            JsonBody::string($body, 'role'),
        );
    }
}
