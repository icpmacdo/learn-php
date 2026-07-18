<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Cache\TaskCacheKeys;
use App\Entity\Task;
use App\Entity\Team;
use App\Entity\User;
use App\Tests\Support\ApiTester;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The caching contract, observed from BOTH sides:
 *
 *  - via the API: after any task write, an immediate list read returns fresh
 *    data (explicit tag invalidation, not TTL expiry, is what makes that true);
 *  - via the backend: asserting directly on the Redis-backed "tasks.cache"
 *    pool that reads populate it, writes wipe it, and -- the crucial proof --
 *    that a cache HIT really serves the stored payload without touching MySQL
 *    (demonstrated by sneaking a row in behind the cache's back).
 *
 * Note the cache lives OUTSIDE the per-test DB transaction rollback; tests
 * stay isolated because every key embeds a team id and team ids are never
 * reused (auto-increment survives rollback).
 */
final class CacheCest
{
    public function taskListReadPopulatesTheRedisCache(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-read@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Cache read team');
        $I->haveTask($teamId, 'First task');

        // Default query shape: page 1, limit 20, no filters, createdAt desc.
        $key = TaskCacheKeys::taskList($teamId, 1, 20, null, null, 'createdAt', 'desc');
        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);

        $I->assertFalse($pool->getItem($key)->isHit(), 'no entry before the first read');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseCodeIs(200);

        $I->assertTrue($pool->getItem($key)->isHit(), 'the read populated Redis');
        $cached = $pool->getItem($key)->get();
        $I->assertIsArray($cached);
        $I->assertSame(1, $cached['total'], 'the cached payload is the response');
    }

    public function distinctQueryShapesGetDistinctEntries(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-shapes@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Shape team');
        $I->haveTask($teamId, 'Todo task');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->sendGet("/api/teams/{$teamId}/tasks", ['status' => 'done']);

        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);
        $plain = $pool->getItem(TaskCacheKeys::taskList($teamId, 1, 20, null, null, 'createdAt', 'desc'));
        $filtered = $pool->getItem(TaskCacheKeys::taskList($teamId, 1, 20, 'done', null, 'createdAt', 'desc'));

        $I->assertTrue($plain->isHit() && $filtered->isHit(), 'each shape cached under its own key');
        $plainPayload = $plain->get();
        $filteredPayload = $filtered->get();
        $I->assertIsArray($plainPayload);
        $I->assertIsArray($filteredPayload);
        $I->assertSame(1, $plainPayload['total']);
        $I->assertSame(0, $filteredPayload['total'], 'the filtered variant cached its own (different) result');
    }

    public function cacheHitsServeFromRedisWithoutQueryingMysql(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-hit@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Hit team');
        $I->haveTask($teamId, 'Visible task');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 1]);

        // Sneak a task into MySQL BEHIND the cache's back (direct Doctrine
        // persist -- no API write, so no tag invalidation fires).
        $team = $I->grabEntityFromRepository(Team::class, ['id' => $teamId]);
        $user = $I->grabEntityFromRepository(User::class, ['email' => 'cache-hit@example.com']);
        $I->haveInRepository(new Task($team, 'Sneaky uncached task', $user));

        // The list is STALE: served from Redis, MySQL never consulted. This
        // is the proof the cache is actually used -- and a live demo of the
        // PRD's stale-data lesson (a write path the invalidation enumeration
        // misses IS the bug; TTL is only the backstop).
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 1]);
        $I->dontSeeResponseContainsJson(['title' => 'Sneaky uncached task']);

        // Any REAL write path invalidates the tag; now everything surfaces.
        $I->haveTask($teamId, 'Legit task');
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 3]);
        $I->seeResponseContainsJson(['title' => 'Sneaky uncached task']);
    }

    public function taskCreateInvalidatesEveryCachedVariant(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-create@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Create team');
        $I->haveTask($teamId, 'Existing', ['status' => 'done']);

        // Cache two variants (page shape + status filter).
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->sendGet("/api/teams/{$teamId}/tasks", ['status' => 'done']);

        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);
        $plainKey = TaskCacheKeys::taskList($teamId, 1, 20, null, null, 'createdAt', 'desc');
        $doneKey = TaskCacheKeys::taskList($teamId, 1, 20, 'done', null, 'createdAt', 'desc');
        $I->assertTrue($pool->getItem($plainKey)->isHit() && $pool->getItem($doneKey)->isHit());

        $I->haveTask($teamId, 'Brand new');

        // ONE tag invalidation wiped BOTH query-shape variants at once --
        // this is why the design uses tags, not per-key deletes.
        $I->assertFalse($pool->getItem($plainKey)->isHit(), 'plain variant wiped');
        $I->assertFalse($pool->getItem($doneKey)->isHit(), 'filtered variant wiped');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 2]);
        $I->seeResponseContainsJson(['title' => 'Brand new']);
    }

    public function taskUpdateIsImmediatelyVisibleInTheList(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-update@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Update team');
        $taskId = $I->haveTask($teamId, 'Mutable task');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['id' => $taskId, 'status' => 'todo']);

        $I->sendPatch("/api/tasks/{$taskId}", ['status' => 'done']);
        $I->seeResponseCodeIs(200);

        // Within the 60s TTL -- without explicit invalidation this would
        // still be the cached "todo".
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['id' => $taskId, 'status' => 'done']);
    }

    public function taskDeleteIsImmediatelyVisibleInTheList(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-delete@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Delete team');
        $keepId = $I->haveTask($teamId, 'Keeper');
        $goneId = $I->haveTask($teamId, 'Goner');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 2]);

        $I->sendDelete("/api/tasks/{$goneId}");
        $I->seeResponseCodeIs(204);

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseContainsJson(['total' => 1]);
        $I->seeResponseContainsJson(['id' => $keepId]);
        $I->dontSeeResponseContainsJson(['id' => $goneId]);
    }

    public function teamDeleteWipesItsCacheEntries(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-team-delete@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Doomed team');
        $I->haveTask($teamId, 'Doomed task');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);
        $key = TaskCacheKeys::taskList($teamId, 1, 20, null, null, 'createdAt', 'desc');
        $I->assertTrue($pool->getItem($key)->isHit());

        $I->sendDelete("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(204);

        // No ghost task lists survive the team.
        $I->assertFalse($pool->getItem($key)->isHit(), 'team delete invalidated the tag');
    }

    public function commentWritesDoNotTouchTheCache(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cache-comments@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Comment team');
        $taskId = $I->haveTask($teamId, 'Discussed task');

        $I->sendGet("/api/teams/{$teamId}/tasks");
        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);
        $key = TaskCacheKeys::taskList($teamId, 1, 20, null, null, 'createdAt', 'desc');
        $I->assertTrue($pool->getItem($key)->isHit());

        // Comments are deliberately excluded from cached payloads, so a
        // comment write needs NO invalidation -- shaping the payload shrank
        // the invalidation surface (the design lesson).
        $I->haveComment($taskId, 'A new comment');
        $I->assertTrue($pool->getItem($key)->isHit(), 'comment write left the cache untouched');

        // And the uncached read path still shows the comment immediately.
        $I->sendGet("/api/tasks/{$taskId}");
        $I->seeResponseContainsJson(['body' => 'A new comment']);
    }
}
