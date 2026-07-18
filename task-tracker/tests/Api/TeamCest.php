<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * Team CRUD + the §4 permission matrix rows for teams:
 * outsider -> 404, member-over-privilege -> 403, admin -> 2xx.
 */
final class TeamCest
{
    public function creatingATeamMakesYouItsAdmin(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('founder@example.com');
        $I->amAuthenticatedWith($token);

        $I->sendPost('/api/teams', ['name' => 'Platform']);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['name' => 'Platform', 'myRole' => 'admin']);

        // ... visible in the members listing too.
        $teamId = $I->grabJsonResponse()['id'];
        $I->sendGet("/api/teams/{$teamId}/members");
        $I->seeResponseCodeIs(200);
        $members = $I->grabJsonResponse()['members'];
        $I->assertCount(1, $members);
        $I->assertSame('admin', $members[0]['role']);
        $I->assertSame('founder@example.com', $members[0]['user']['email']);
    }

    public function blankTeamNameIs422(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('blank@example.com');
        $I->amAuthenticatedWith($token);

        $I->sendPost('/api/teams', ['name' => '']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'name']]]);
    }

    public function teamListIsScopedToTheCaller(ApiTester $I): void
    {
        $alice = $I->haveUserWithToken('alice-teams@example.com');
        $I->amAuthenticatedWith($alice);
        $I->haveTeam('Alice Team');

        $bob = $I->haveUserWithToken('bob-teams@example.com');
        $I->amAuthenticatedWith($bob);
        $I->haveTeam('Bob Team');

        $I->sendGet('/api/teams');
        $I->seeResponseCodeIs(200);
        $teams = $I->grabJsonResponse()['teams'];
        $I->assertCount(1, $teams, "other people's teams are not even listed");
        $I->assertSame('Bob Team', $teams[0]['name']);
    }

    public function membersSeeTheTeamAdminsCanRenameIt(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('rename-admin@example.com');
        $I->haveUser('rename-member@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Old Name');
        $I->haveMember($teamId, 'rename-member@example.com');

        $member = $I->haveApiTokenFor('rename-member@example.com');

        // Member: can view...
        $I->amAuthenticatedWith($member);
        $I->sendGet("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['name' => 'Old Name', 'myRole' => 'member']);

        // ... but renaming is admin-only: 403 (the member may know the team
        // exists, so 403 leaks nothing).
        $I->sendPatch("/api/teams/{$teamId}", ['name' => 'Member Coup']);
        $I->seeResponseCodeIs(403);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);

        // Admin renames fine.
        $I->amAuthenticatedWith($admin);
        $I->sendPatch("/api/teams/{$teamId}", ['name' => 'New Name']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['name' => 'New Name']);
    }

    public function outsidersGet404ForEverythingTeamScoped(ApiTester $I): void
    {
        $owner = $I->haveUserWithToken('owner-404@example.com');
        $I->amAuthenticatedWith($owner);
        $teamId = $I->haveTeam('Secret Team');

        $outsider = $I->haveUserWithToken('outsider-404@example.com');
        $I->amAuthenticatedWith($outsider);

        // View, rename, delete, members, tasks: existence is never confirmed.
        $I->sendGet("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(404);
        $I->sendPatch("/api/teams/{$teamId}", ['name' => 'X']);
        $I->seeResponseCodeIs(404);
        $I->sendDelete("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(404);
        $I->sendGet("/api/teams/{$teamId}/members");
        $I->seeResponseCodeIs(404);
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseCodeIs(404);
        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => 'sneaky']);
        $I->seeResponseCodeIs(404);

        // Identical body to a genuinely nonexistent team.
        $I->sendGet("/api/teams/{$teamId}");
        $probeExisting = $I->grabResponse();
        $I->sendGet('/api/teams/999999');
        $I->seeResponseCodeIs(404);
        $I->assertSame($I->grabResponse(), $probeExisting);
    }

    public function memberCannotDeleteTheTeamAdminCan(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('del-admin@example.com');
        $I->haveUser('del-member@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Doomed');
        $I->haveMember($teamId, 'del-member@example.com');
        $taskId = $I->haveTask($teamId, 'Task in doomed team');

        $member = $I->haveApiTokenFor('del-member@example.com');
        $I->amAuthenticatedWith($member);
        $I->sendDelete("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(403);

        $I->amAuthenticatedWith($admin);
        $I->sendDelete("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(204);

        // Cascade: the team's task went with it.
        $I->sendGet("/api/tasks/{$taskId}");
        $I->seeResponseCodeIs(404);
        $I->sendGet("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(404);
    }
}
