<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * Task CRUD + the §4 matrix rows for tasks: any member creates/edits, delete
 * is creator-or-admin, outsiders see nothing but 404s.
 */
final class TaskCest
{
    public function memberCreatesATaskWithAllFields(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('tc-admin@example.com');
        $assigneeId = $I->haveUser('tc-assignee@example.com', 'Assignee');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Builders');
        $I->haveMember($teamId, 'tc-assignee@example.com');

        $I->sendPost("/api/teams/{$teamId}/tasks", [
            'title' => 'Ship it',
            'description' => 'All of it',
            'status' => 'in_progress',
            'priority' => 'high',
            'assigneeId' => $assigneeId,
            'dueDate' => '2026-08-01',
        ]);

        $I->seeResponseCodeIs(201);
        $body = $I->grabJsonResponse();
        $I->assertSame('Ship it', $body['title']);
        $I->assertSame('in_progress', $body['status']);
        $I->assertSame('high', $body['priority']);
        $I->assertSame('2026-08-01', $body['dueDate']);
        $I->assertSame($teamId, $body['teamId']);
        $I->assertSame('tc-assignee@example.com', $body['assignee']['email']);
        $I->assertSame('tc-admin@example.com', $body['createdBy']['email']);
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['createdAt']);
    }

    public function taskDefaultsAreTodoAndMedium(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('tdef@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Defaults');

        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'Bare minimum']);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'status' => 'todo',
            'priority' => 'medium',
            'assignee' => null,
            'dueDate' => null,
            'description' => null,
        ]);
    }

    public function taskCreationValidationFailures(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('tval@example.com');
        $outsiderId = $I->haveUser('tval-outsider@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Fussy');

        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => '']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'title']]]);

        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'x', 'status' => 'blocked']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'status']]]);

        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'x', 'priority' => 'urgent']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'priority']]]);

        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'x', 'dueDate' => 'tomorrow']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'dueDate']]]);

        // A registered user who is NOT a team member cannot be assigned.
        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'x', 'assigneeId' => $outsiderId]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'assigneeId', 'message' => 'Assignee must be a member of the team.']]]);

        // Same for an id that does not exist at all -- indistinguishable.
        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'x', 'assigneeId' => 999999]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'assigneeId']]]);
    }

    public function showIncludesCommentsOldestFirst(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('tshow@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Talkers');
        $taskId = $I->haveTask($teamId, 'Discussed');
        $I->haveComment($taskId, 'first');
        $I->haveComment($taskId, 'second');

        $I->sendGet("/api/tasks/{$taskId}");

        $I->seeResponseCodeIs(200);
        $body = $I->grabJsonResponse();
        $I->assertSame('Discussed', $body['title']);
        $I->assertCount(2, $body['comments']);
        $I->assertSame('first', $body['comments'][0]['body']);
        $I->assertSame('second', $body['comments'][1]['body']);
    }

    public function anyMemberEditsAndPatchClearsNullableFields(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('tedit-admin@example.com');
        $memberId = $I->haveUser('tedit-member@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Editors');
        $I->haveMember($teamId, 'tedit-member@example.com');
        $taskId = $I->haveTask($teamId, 'Original', [
            'description' => 'desc',
            'assigneeId' => $memberId,
            'dueDate' => '2026-09-01',
        ]);

        // The (non-creator) member may edit any field.
        $member = $I->haveApiTokenFor('tedit-member@example.com');
        $I->amAuthenticatedWith($member);
        $I->sendPatch("/api/tasks/{$taskId}", ['title' => 'Renamed', 'status' => 'done']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['title' => 'Renamed', 'status' => 'done']);

        // Explicit nulls clear the nullable fields.
        $I->sendPatch("/api/tasks/{$taskId}", ['description' => null, 'assigneeId' => null, 'dueDate' => null]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['description' => null, 'assignee' => null, 'dueDate' => null]);

        // Invalid enum on PATCH.
        $I->sendPatch("/api/tasks/{$taskId}", ['status' => 'nope']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'status']]]);

        // Blank title on PATCH.
        $I->sendPatch("/api/tasks/{$taskId}", ['title' => '']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'title']]]);
    }

    public function deleteIsCreatorOrAdminOnly(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('tdel-admin@example.com');
        $I->haveUser('tdel-creator@example.com');
        $I->haveUser('tdel-other@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Deleters');
        $I->haveMember($teamId, 'tdel-creator@example.com');
        $I->haveMember($teamId, 'tdel-other@example.com');

        $creator = $I->haveApiTokenFor('tdel-creator@example.com');
        $other = $I->haveApiTokenFor('tdel-other@example.com');

        $I->amAuthenticatedWith($creator);
        $taskA = $I->haveTask($teamId, 'Creator deletes me');
        $taskB = $I->haveTask($teamId, 'Admin deletes me');

        // A member who is neither creator nor admin: 403.
        $I->amAuthenticatedWith($other);
        $I->sendDelete("/api/tasks/{$taskA}");
        $I->seeResponseCodeIs(403);

        // The creator: 204.
        $I->amAuthenticatedWith($creator);
        $I->sendDelete("/api/tasks/{$taskA}");
        $I->seeResponseCodeIs(204);

        // A team admin (not the creator): 204 -- moderation.
        $I->amAuthenticatedWith($admin);
        $I->sendDelete("/api/tasks/{$taskB}");
        $I->seeResponseCodeIs(204);

        $I->sendGet("/api/tasks/{$taskB}");
        $I->seeResponseCodeIs(404);
    }

    public function outsiderGets404ForTaskEndpoints(ApiTester $I): void
    {
        $owner = $I->haveUserWithToken('tout-owner@example.com');
        $I->amAuthenticatedWith($owner);
        $teamId = $I->haveTeam('Private');
        $taskId = $I->haveTask($teamId, 'Invisible task');

        $outsider = $I->haveUserWithToken('tout-outsider@example.com');
        $I->amAuthenticatedWith($outsider);

        $I->sendGet("/api/tasks/{$taskId}");
        $I->seeResponseCodeIs(404);
        $I->sendPatch("/api/tasks/{$taskId}", ['title' => 'Hijack']);
        $I->seeResponseCodeIs(404);
        $I->sendDelete("/api/tasks/{$taskId}");
        $I->seeResponseCodeIs(404);
        $I->sendPost("/api/tasks/{$taskId}/comments", ['body' => 'psst']);
        $I->seeResponseCodeIs(404);
    }

    public function unauthenticatedTaskAccessIs401(ApiTester $I): void
    {
        // 401 is reserved for "who are you?" -- no token at all.
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendGet('/api/tasks/1');
        $I->seeResponseCodeIs(401);
        $I->sendPost('/api/teams/1/tasks', ['title' => 'x']);
        $I->seeResponseCodeIs(401);
    }
}
