<?php

declare(strict_types=1);

namespace App\Dto;

use App\Http\JsonBody;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The validated shape of a PATCH /api/tasks/{id} body: any subset of fields.
 *
 * PATCH semantics need "absent" and "explicitly null" told apart for the
 * nullable fields (null CLEARS description/assignee/dueDate), hence the
 * *Provided flags computed from key presence. `title` can never be null --
 * a task always has one -- so a null title is a 400.
 */
final class UpdateTaskRequest
{
    public function __construct(
        #[Assert\Length(min: 1, max: 255, minMessage: 'This value should not be blank.')]
        public readonly ?string $title,
        #[Assert\Length(max: 10000)]
        public readonly ?string $description,
        public readonly bool $descriptionProvided,
        #[Assert\Choice(choices: ['todo', 'in_progress', 'done'], message: 'Status must be "todo", "in_progress" or "done".')]
        public readonly ?string $status,
        #[Assert\Choice(choices: ['low', 'medium', 'high'], message: 'Priority must be "low", "medium" or "high".')]
        public readonly ?string $priority,
        public readonly ?int $assigneeId,
        public readonly bool $assigneeIdProvided,
        #[Assert\Date(message: 'Due date must be a valid Y-m-d date.')]
        public readonly ?string $dueDate,
        public readonly bool $dueDateProvided,
    ) {
    }

    /** @param array<string, mixed> $body */
    public static function fromArray(array $body): self
    {
        if (\array_key_exists('title', $body) && $body['title'] === null) {
            throw new BadRequestHttpException('Field "title" must be a string.');
        }

        $dueDate = JsonBody::optionalString($body, 'dueDate');

        return new self(
            \array_key_exists('title', $body) ? JsonBody::string($body, 'title') : null,
            JsonBody::optionalString($body, 'description'),
            \array_key_exists('description', $body),
            JsonBody::optionalString($body, 'status'),
            JsonBody::optionalString($body, 'priority'),
            JsonBody::optionalInt($body, 'assigneeId'),
            \array_key_exists('assigneeId', $body),
            $dueDate === '' ? null : $dueDate,
            \array_key_exists('dueDate', $body),
        );
    }
}
