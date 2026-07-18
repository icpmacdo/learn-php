<?php

declare(strict_types=1);

namespace App\Cache;

use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * The explicit-invalidation half of the caching contract, in one place:
 * EVERY write path that changes what a cached task list / team summary would
 * contain (task create/update/delete, team delete) calls invalidate().
 *
 * Call it AFTER the flush -- invalidating first would let a concurrent read
 * re-cache the pre-write state. One tag wipes every page/filter/sort variant
 * plus the dashboard summary at once; per-key deletion could never enumerate
 * them.
 */
final class TeamTasksCacheInvalidator
{
    public function __construct(
        private readonly TagAwareCacheInterface $tasksCache,
    ) {
    }

    public function invalidate(int $teamId): void
    {
        $this->tasksCache->invalidateTags([TaskCacheKeys::teamTasksTag($teamId)]);
    }
}
