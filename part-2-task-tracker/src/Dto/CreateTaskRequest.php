<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a POST /api/teams/{id}/tasks body. Only `title` is
 * required; status and priority fall back to their column defaults.
 *
 * `assigneeId` referential validity (must be a team member) is checked by the
 * controller against the membership table -- a relationship rule, not a
 * value-shape rule, so it does not live on this DTO.
 */
final class CreateTaskRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $title,
        #[Assert\Length(max: 10000)]
        public readonly ?string $description,
        #[Assert\Choice(choices: ['todo', 'in_progress', 'done'], message: 'Status must be "todo", "in_progress" or "done".')]
        public readonly ?string $status,
        #[Assert\Choice(choices: ['low', 'medium', 'high'], message: 'Priority must be "low", "medium" or "high".')]
        public readonly ?string $priority,
        public readonly ?int $assigneeId,
        #[Assert\Date(message: 'Due date must be a valid Y-m-d date.')]
        public readonly ?string $dueDate,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        $dueDate = JsonBody::optionalString($body, 'dueDate');

        return new self(
            JsonBody::string($body, 'title'),
            JsonBody::optionalString($body, 'description'),
            JsonBody::optionalString($body, 'status'),
            JsonBody::optionalString($body, 'priority'),
            JsonBody::optionalInt($body, 'assigneeId'),
            $dueDate === '' ? null : $dueDate, // '' would slip past Assert\Date (empty values are skipped)
        );
    }
}
