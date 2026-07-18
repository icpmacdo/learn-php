<?php

declare(strict_types=1);

namespace App\Task;

use App\Entity\Team;

/**
 * "Give me a page of this team's tasks for this validated query.".
 *
 * The controller depends on this abstraction only. DoctrineTaskListProvider
 * answers from MySQL; CachingTaskListProvider decorates it with the Redis
 * read-through (see services.yaml) -- caching is added by composition, not by
 * editing the query code.
 */
interface TaskListProviderInterface
{
    /**
     * @return array{tasks: list<array<string, mixed>>, page: int, limit: int, total: int, pages: int}
     */
    public function list(Team $team, TaskListQuery $query): array;
}
