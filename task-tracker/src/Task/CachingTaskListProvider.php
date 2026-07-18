<?php

declare(strict_types=1);

namespace App\Task;

use App\Cache\TaskCacheKeys;
use App\Entity\Team;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Redis read-through as a DECORATOR: same interface as the inner provider,
 * all cache mechanics (key, tag, TTL) owned here. On a hit, the inner
 * provider -- and therefore MySQL -- is never touched.
 *
 * One key per team per validated query shape; every variant carries the
 * team's ONE tag so the write paths can wipe them all at once
 * (TeamTasksCacheInvalidator). Comments are deliberately absent from this
 * payload: comment writes therefore need no invalidation at all.
 */
final class CachingTaskListProvider implements TaskListProviderInterface
{
    public function __construct(
        private readonly TaskListProviderInterface $inner,
        private readonly TagAwareCacheInterface $tasksCache,
        #[Autowire('%app.cache.task_list_ttl%')]
        private readonly int $ttl,
    ) {
    }

    public function list(Team $team, TaskListQuery $query): array
    {
        $teamId = $team->getId();
        \assert($teamId !== null);

        $key = TaskCacheKeys::taskList(
            $teamId,
            $query->page,
            $query->limit,
            $query->statusValue(),
            $query->assigneeValue(),
            $query->sort,
            $query->direction,
        );

        return $this->tasksCache->get(
            $key,
            function (ItemInterface $item) use ($team, $teamId, $query): array {
                $item->expiresAfter($this->ttl);
                $item->tag(TaskCacheKeys::teamTasksTag($teamId));

                return $this->inner->list($team, $query);
            },
        );
    }
}
