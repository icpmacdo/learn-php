<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * The GET /api/teams/{id}/tasks contract: pagination math, every filter,
 * every sort key (priority by RANK), and loud 422s for anything off-contract.
 */
final class TaskListCest
{
    public function paginationMathIsExact(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('page@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Paged');
        for ($i = 1; $i <= 25; ++$i) {
            $I->haveTask($teamId, "Task {$i}");
        }

        // Defaults: page 1, limit 20, newest first.
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseCodeIs(200);
        $body = $I->grabJsonResponse();
        $I->assertCount(20, $body['tasks']);
        $I->assertSame(1, $body['page']);
        $I->assertSame(20, $body['limit']);
        $I->assertSame(25, $body['total']);
        $I->assertSame(2, $body['pages']);
        // createdAt desc with id as tiebreak: the last created task leads.
        $I->assertSame('Task 25', $body['tasks'][0]['title']);

        // Page 2 has the remaining 5.
        $I->sendGet("/api/teams/{$teamId}/tasks?page=2");
        $body = $I->grabJsonResponse();
        $I->assertCount(5, $body['tasks']);
        $I->assertSame(2, $body['page']);
        $I->assertSame('Task 1', $body['tasks'][4]['title']);

        // Custom limit changes the page count.
        $I->sendGet("/api/teams/{$teamId}/tasks?limit=10&page=3");
        $body = $I->grabJsonResponse();
        $I->assertCount(5, $body['tasks']);
        $I->assertSame(3, $body['pages']);

        // Beyond the last page: empty but well-formed, still 200.
        $I->sendGet("/api/teams/{$teamId}/tasks?page=99");
        $body = $I->grabJsonResponse();
        $I->assertCount(0, $body['tasks']);
        $I->assertSame(25, $body['total']);
    }

    public function statusAndAssigneeFiltersNarrowTheList(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('filter@example.com');
        $helperId = $I->haveUser('filter-helper@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Filtered');
        $I->haveMember($teamId, 'filter-helper@example.com');

        $I->haveTask($teamId, 'todo unassigned');
        $I->haveTask($teamId, 'done assigned', ['status' => 'done', 'assigneeId' => $helperId]);
        $I->haveTask($teamId, 'in_progress assigned', ['status' => 'in_progress', 'assigneeId' => $helperId]);

        $I->sendGet("/api/teams/{$teamId}/tasks?status=done");
        $body = $I->grabJsonResponse();
        $I->assertSame(1, $body['total']);
        $I->assertSame('done assigned', $body['tasks'][0]['title']);

        $I->sendGet("/api/teams/{$teamId}/tasks?assignee={$helperId}");
        $I->assertSame(2, $I->grabJsonResponse()['total']);

        // The literal "none" selects unassigned tasks.
        $I->sendGet("/api/teams/{$teamId}/tasks?assignee=none");
        $body = $I->grabJsonResponse();
        $I->assertSame(1, $body['total']);
        $I->assertSame('todo unassigned', $body['tasks'][0]['title']);

        // Filters combine.
        $I->sendGet("/api/teams/{$teamId}/tasks?status=done&assignee={$helperId}");
        $I->assertSame(1, $I->grabJsonResponse()['total']);
        $I->sendGet("/api/teams/{$teamId}/tasks?status=todo&assignee={$helperId}");
        $I->assertSame(0, $I->grabJsonResponse()['total']);
    }

    public function prioritySortsByRankNotAlphabetically(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('prio@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Ranked');
        $I->haveTask($teamId, 'medium task', ['priority' => 'medium']);
        $I->haveTask($teamId, 'low task', ['priority' => 'low']);
        $I->haveTask($teamId, 'high task', ['priority' => 'high']);

        // Alphabetical desc would be medium > low > high; rank desc is
        // high > medium > low.
        $I->sendGet("/api/teams/{$teamId}/tasks?sort=priority&direction=desc");
        $titles = array_column($I->grabJsonResponse()['tasks'], 'title');
        $I->assertSame(['high task', 'medium task', 'low task'], $titles);

        $I->sendGet("/api/teams/{$teamId}/tasks?sort=priority&direction=asc");
        $titles = array_column($I->grabJsonResponse()['tasks'], 'title');
        $I->assertSame(['low task', 'medium task', 'high task'], $titles);
    }

    public function dueDateSortOrdersByDate(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('due@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Dated');
        $I->haveTask($teamId, 'march', ['dueDate' => '2026-03-01']);
        $I->haveTask($teamId, 'january', ['dueDate' => '2026-01-01']);
        $I->haveTask($teamId, 'february', ['dueDate' => '2026-02-01']);

        $I->sendGet("/api/teams/{$teamId}/tasks?sort=dueDate&direction=asc");
        $titles = array_column($I->grabJsonResponse()['tasks'], 'title');
        $I->assertSame(['january', 'february', 'march'], $titles);
    }

    public function offContractParamsAre422NeverSilentFallback(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('loud@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Loud');
        $I->haveTask($teamId, 'only task');

        $cases = [
            ['page=0', 'page'],
            ['page=abc', 'page'],
            ['page=-1', 'page'],
            ['limit=0', 'limit'],
            ['limit=101', 'limit'],
            ['limit=abc', 'limit'],
            ['status=blocked', 'status'],
            ['assignee=somebody', 'assignee'],
            ['assignee=0', 'assignee'],
            ['sort=title', 'sort'],
            ['sort=id;DROP', 'sort'],
            ['direction=up', 'direction'],
        ];

        foreach ($cases as [$query, $field]) {
            $I->sendGet("/api/teams/{$teamId}/tasks?{$query}");
            $I->seeResponseCodeIs(422);
            $I->seeResponseContainsJson(['errors' => [['field' => $field]]]);
        }
    }
}
