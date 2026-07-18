<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a PATCH /api/teams/{id}/members/{userId} body.
 */
final class ChangeRoleRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['member', 'admin'], message: 'Role must be "member" or "admin".')]
        public readonly string $role,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(JsonBody::string($body, 'role'));
    }
}
