<?php

declare(strict_types=1);

namespace App\Task;

use App\Enum\TaskStatus;
use Symfony\Component\HttpFoundation\Request;

/**
 * The validated task-list query: page/limit/filters/sort as one immutable
 * value object. fromRequest() is the ONLY way in, so an instance existing
 * means every parameter already passed the contract -- providers never
 * re-validate, and "loud 422 on anything outside the contract" lives in
 * exactly one place (each violation throws InvalidTaskListQuery naming the
 * parameter; silent fallbacks would let a typo return data that LOOKS right).
 *
 * Extracted from TaskController::list() in the stage-3 SOLID refactor
 * (docs/solid-refactor.md).
 */
final class TaskListQuery
{
    /** API-facing sort fields; the Doctrine provider maps them to DQL. */
    public const SORT_FIELDS = ['createdAt', 'dueDate', 'priority', 'status'];

    private function __construct(
        public readonly int $page,
        public readonly int $limit,
        public readonly ?TaskStatus $status,
        public readonly ?int $assigneeId,
        public readonly bool $unassignedOnly,
        public readonly string $sort,
        public readonly string $direction,
    ) {
    }

    /** @throws InvalidTaskListQuery on the first parameter outside the contract */
    public static function fromRequest(Request $request): self
    {
        $page = self::intParam($request, 'page', min: 1, default: 1);
        $limit = self::intParam($request, 'limit', min: 1, default: 20, max: 100);

        $statusParam = self::stringParam($request, 'status');
        $status = null;
        if ($statusParam !== null) {
            $status = TaskStatus::tryFrom($statusParam);
            if ($status === null) {
                throw new InvalidTaskListQuery('status', 'Status must be "todo", "in_progress" or "done".');
            }
        }

        $assigneeParam = self::stringParam($request, 'assignee');
        $assigneeId = null; // int = filter by user id; 'none' = unassigned only
        $unassignedOnly = false;
        if ($assigneeParam !== null) {
            if ($assigneeParam === 'none') {
                $unassignedOnly = true;
            } elseif (ctype_digit($assigneeParam) && $assigneeParam !== '0') {
                $assigneeId = (int) $assigneeParam;
            } else {
                throw new InvalidTaskListQuery('assignee', 'Assignee must be a user id or "none".');
            }
        }

        $sort = self::stringParam($request, 'sort') ?? 'createdAt';
        if (!\in_array($sort, self::SORT_FIELDS, true)) {
            throw new InvalidTaskListQuery('sort', 'Sort must be one of: createdAt, dueDate, priority, status.');
        }

        $direction = self::stringParam($request, 'direction') ?? 'desc';
        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidTaskListQuery('direction', 'Direction must be "asc" or "desc".');
        }

        return new self($page, $limit, $status, $assigneeId, $unassignedOnly, $sort, $direction);
    }

    /**
     * Canonical string forms for the cache key: identical to the raw request
     * values for every VALID request (the only kind that gets this far).
     */
    public function statusValue(): ?string
    {
        return $this->status?->value;
    }

    public function assigneeValue(): ?string
    {
        if ($this->unassignedOnly) {
            return 'none';
        }

        return $this->assigneeId !== null ? (string) $this->assigneeId : null;
    }

    private static function stringParam(Request $request, string $name): ?string
    {
        $value = $request->query->get($name);

        return $value === null ? null : (string) $value;
    }

    /** Query params arrive as strings; only base-10 digits within range pass. */
    private static function intParam(Request $request, string $name, int $min, int $default, ?int $max = null): int
    {
        $raw = self::stringParam($request, $name);
        if ($raw === null) {
            return $default;
        }

        if (!ctype_digit($raw) || (int) $raw < $min || ($max !== null && (int) $raw > $max)) {
            $range = $max !== null ? sprintf('an integer between %d and %d', $min, $max) : sprintf('an integer >= %d', $min);

            throw new InvalidTaskListQuery($name, sprintf('%s must be %s.', ucfirst($name), $range));
        }

        return (int) $raw;
    }
}
