<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Cache\TaskCacheKeys;
use Codeception\Test\Unit;

/**
 * The cache key builder must be DETERMINISTIC (the reader's key has to match
 * the writer's byte-for-byte -- a drifting key is a 0% hit rate that looks
 * like a working cache) and COLLISION-FREE across query shapes (two different
 * filters sharing a key would serve one filter's rows to the other).
 */
final class TaskCacheKeysTest extends Unit
{
    public function testSameInputsAlwaysProduceTheSameKey(): void
    {
        $a = TaskCacheKeys::taskList(7, 2, 50, 'todo', 'none', 'priority', 'asc');
        $b = TaskCacheKeys::taskList(7, 2, 50, 'todo', 'none', 'priority', 'asc');

        $this->assertSame($a, $b);
    }

    public function testKeyFollowsThePrdScheme(): void
    {
        // task_list.{teamId}.{sha1(query shape)} -- teamId stays readable in
        // redis-cli, the query shape is hashed (40 hex chars).
        $key = TaskCacheKeys::taskList(7, 1, 20, null, null, 'createdAt', 'desc');

        $this->assertMatchesRegularExpression('/^task_list\.7\.[0-9a-f]{40}$/', $key);
    }

    public function testEveryParameterParticipatesInTheKey(): void
    {
        $base = TaskCacheKeys::taskList(7, 1, 20, null, null, 'createdAt', 'desc');

        $variants = [
            'teamId' => TaskCacheKeys::taskList(8, 1, 20, null, null, 'createdAt', 'desc'),
            'page' => TaskCacheKeys::taskList(7, 2, 20, null, null, 'createdAt', 'desc'),
            'limit' => TaskCacheKeys::taskList(7, 1, 21, null, null, 'createdAt', 'desc'),
            'status' => TaskCacheKeys::taskList(7, 1, 20, 'done', null, 'createdAt', 'desc'),
            'assignee' => TaskCacheKeys::taskList(7, 1, 20, null, '3', 'createdAt', 'desc'),
            'sort' => TaskCacheKeys::taskList(7, 1, 20, null, null, 'dueDate', 'desc'),
            'direction' => TaskCacheKeys::taskList(7, 1, 20, null, null, 'createdAt', 'asc'),
        ];

        foreach ($variants as $param => $key) {
            $this->assertNotSame($base, $key, sprintf('changing "%s" must change the key', $param));
        }

        // ...and all variants differ from each other too.
        $this->assertCount(\count($variants), array_unique($variants));
    }

    public function testValuesCannotBleedAcrossFieldPositions(): void
    {
        // status='todo' vs assignee='todo' would collide if the shape were
        // naively concatenated without positions.
        $statusSet = TaskCacheKeys::taskList(7, 1, 20, 'todo', null, 'createdAt', 'desc');
        $assigneeSet = TaskCacheKeys::taskList(7, 1, 20, null, 'todo', 'createdAt', 'desc');

        $this->assertNotSame($statusSet, $assigneeSet);
    }

    public function testSummaryKeyAndTagFollowTheScheme(): void
    {
        $this->assertSame('team_summary.7', TaskCacheKeys::teamSummary(7));
        $this->assertSame('team_tasks.7', TaskCacheKeys::teamTasksTag(7));
    }

    public function testListAndSummaryShareTheTeamTag(): void
    {
        // The whole invalidation design: ONE tag covers both cached reads,
        // so a task write cannot invalidate one and forget the other.
        $tag = TaskCacheKeys::teamTasksTag(7);

        $this->assertStringContainsString('7', $tag);
        $this->assertNotSame(TaskCacheKeys::teamTasksTag(8), $tag);
    }
}
