<?php

declare(strict_types=1);

namespace App\Cache;

/**
 * The single place where cache key/tag strings are built -- writers and
 * readers must agree on these byte-for-byte, so neither side hand-rolls them.
 *
 * Scheme (PRD section 7):
 *   task_list.{teamId}.{sha1 of the query shape}   TTL 60s   tag team_tasks.{teamId}
 *   team_summary.{teamId}                          TTL 300s  tag team_tasks.{teamId}
 *
 * The task-list key hashes the *validated* query params in a fixed order, so
 * every page/filter/sort combination gets its own entry. That is exactly why
 * invalidation happens by TAG: one invalidateTags(["team_tasks.{id}"]) wipes
 * every variant at once -- per-key deletion could never enumerate them.
 */
final class TaskCacheKeys
{
    private function __construct()
    {
    }

    public static function taskList(
        int $teamId,
        int $page,
        int $limit,
        ?string $status,
        ?string $assignee,
        string $sort,
        string $direction,
    ): string {
        // Fixed order + explicit '' for "absent": ?status=todo and ?assignee=todo
        // must never collide, and absent-vs-present must stay distinct.
        $shape = implode('|', [$page, $limit, $status ?? '', $assignee ?? '', $sort, $direction]);

        return sprintf('task_list.%d.%s', $teamId, sha1($shape));
    }

    public static function teamSummary(int $teamId): string
    {
        return 'team_summary.'.$teamId;
    }

    /** The one tag both cached reads share; every task/team write path invalidates it. */
    public static function teamTasksTag(int $teamId): string
    {
        return 'team_tasks.'.$teamId;
    }
}
