<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of POST /api/tasks/{id}/comments and
 * PATCH /api/comments/{id} bodies -- both are just the body text.
 */
final class CommentBodyRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 5000)]
        public readonly string $body,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        return new self(JsonBody::string($body, 'body'));
    }
}
