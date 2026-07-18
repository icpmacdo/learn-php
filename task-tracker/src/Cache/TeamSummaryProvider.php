<?php

declare(strict_types=1);

namespace App\Cache;

use App\Entity\Team;
use App\Enum\TaskStatus;
use App\Repository\TaskRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The dashboard's per-team task counts, read through the Redis cache
 * (team_summary.{teamId}, TTL 300s). Tagged team_tasks.{teamId} -- the same
 * tag as the cached task lists -- so every task write invalidates both with
 * one call; there is no separate summary invalidation path to forget.
 */
final class TeamSummaryProvider
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TagAwareCacheInterface $tasksCache,
        #[Autowire('%app.cache.team_summary_ttl%')]
        private readonly int $ttl,
    ) {
    }

    /**
     * @return array{total: int, todo: int, in_progress: int, done: int}
     */
    public function summaryFor(Team $team): array
    {
        $teamId = $team->getId();
        \assert($teamId !== null);

        return $this->tasksCache->get(
            TaskCacheKeys::teamSummary($teamId),
            function (ItemInterface $item) use ($team, $teamId): array {
                $item->expiresAfter($this->ttl);
                $item->tag(TaskCacheKeys::teamTasksTag($teamId));

                $byStatus = $this->tasks->countByStatus($team);

                return [
                    'total' => array_sum($byStatus),
                    'todo' => $byStatus[TaskStatus::Todo->value] ?? 0,
                    'in_progress' => $byStatus[TaskStatus::InProgress->value] ?? 0,
                    'done' => $byStatus[TaskStatus::Done->value] ?? 0,
                ];
            },
        );
    }
}
