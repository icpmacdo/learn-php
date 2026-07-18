<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * Membership management: the admin-only grid, the leave-team carve-out and
 * the last-admin rule.
 */
final class MemberCest
{
    public function adminAddsMembersAndAdmins(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('madd-admin@example.com');
        $I->haveUser('madd-member@example.com', 'Plain Member');
        $I->haveUser('madd-admin2@example.com', 'Second Admin');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Growing');

        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'madd-member@example.com', 'role' => 'member']);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['role' => 'member', 'user' => ['email' => 'madd-member@example.com']]);

        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'madd-admin2@example.com', 'role' => 'admin']);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['role' => 'admin']);

        $I->sendGet("/api/teams/{$teamId}/members");
        $I->assertCount(3, $I->grabJsonResponse()['members']);
    }

    public function addMemberValidationFailures(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('mval-admin@example.com');
        $I->haveUser('mval-member@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Strict');
        $I->haveMember($teamId, 'mval-member@example.com');

        // Unknown email.
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'nobody@example.com', 'role' => 'member']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'email', 'message' => 'No account with this email exists.']]]);

        // Already a member.
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'mval-member@example.com', 'role' => 'member']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'email', 'message' => 'This user is already a member of the team.']]]);

        // Invalid role.
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'mval-member@example.com', 'role' => 'owner']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'role']]]);
    }

    public function membersCannotManageMembership(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('mm-admin@example.com');
        $I->haveUser('mm-member@example.com');
        $I->haveUser('mm-victim@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Locked');
        $I->haveMember($teamId, 'mm-member@example.com');
        $I->haveMember($teamId, 'mm-victim@example.com');
        $victimId = $I->grabJsonResponse()['user']['id'];

        $member = $I->haveApiTokenFor('mm-member@example.com');
        $I->amAuthenticatedWith($member);

        // A member can SEE the roster...
        $I->sendGet("/api/teams/{$teamId}/members");
        $I->seeResponseCodeIs(200);

        // ... but every management verb is 403.
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'mm-victim@example.com', 'role' => 'member']);
        $I->seeResponseCodeIs(403);
        $I->sendPatch("/api/teams/{$teamId}/members/{$victimId}", ['role' => 'admin']);
        $I->seeResponseCodeIs(403);
        $I->sendDelete("/api/teams/{$teamId}/members/{$victimId}");
        $I->seeResponseCodeIs(403);
    }

    public function outsiderGets404ForMembershipEndpoints(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('mo-admin@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Invisible');

        $outsider = $I->haveUserWithToken('mo-outsider@example.com');
        $I->amAuthenticatedWith($outsider);

        $I->sendGet("/api/teams/{$teamId}/members");
        $I->seeResponseCodeIs(404);
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => 'mo-outsider@example.com', 'role' => 'admin']);
        $I->seeResponseCodeIs(404);
        $I->sendPatch("/api/teams/{$teamId}/members/1", ['role' => 'admin']);
        $I->seeResponseCodeIs(404);
        $I->sendDelete("/api/teams/{$teamId}/members/1");
        $I->seeResponseCodeIs(404);
    }

    public function roleChangesRespectTheLastAdminRule(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('ra-admin@example.com');
        $memberId = $I->haveUser('ra-member@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Succession');
        $I->haveMember($teamId, 'ra-member@example.com');

        $adminId = null; // find own user id via /api/me
        $I->sendGet('/api/me');
        $adminId = $I->grabJsonResponse()['id'];

        // Demoting yourself while you are the only admin: 422.
        $I->sendPatch("/api/teams/{$teamId}/members/{$adminId}", ['role' => 'member']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'role', 'message' => 'A team must keep at least one admin.']]]);

        // Unknown member id -> 404 (while still an admin).
        $I->sendPatch("/api/teams/{$teamId}/members/999999", ['role' => 'member']);
        $I->seeResponseCodeIs(404);

        // Promote the member -> two admins.
        $I->sendPatch("/api/teams/{$teamId}/members/{$memberId}", ['role' => 'admin']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['role' => 'admin']);

        // Now demoting yourself is fine...
        $I->sendPatch("/api/teams/{$teamId}/members/{$adminId}", ['role' => 'member']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['role' => 'member']);

        // ... and having done so, managing members is instantly a 403:
        // the demotion took effect for authorization within the same session.
        $I->sendPatch("/api/teams/{$teamId}/members/{$memberId}", ['role' => 'member']);
        $I->seeResponseCodeIs(403);
    }

    public function removalAndLeavingRespectTheMatrix(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('rm-admin@example.com');
        $memberId = $I->haveUser('rm-member@example.com');
        $leaverId = $I->haveUser('rm-leaver@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Shrinking');
        $I->haveMember($teamId, 'rm-member@example.com');
        $I->haveMember($teamId, 'rm-leaver@example.com');
        $I->sendGet('/api/me');
        $adminId = $I->grabJsonResponse()['id'];

        // A member leaves (removes SELF) -- allowed.
        $leaver = $I->haveApiTokenFor('rm-leaver@example.com');
        $I->amAuthenticatedWith($leaver);
        $I->sendDelete("/api/teams/{$teamId}/members/{$leaverId}");
        $I->seeResponseCodeIs(204);

        // ... and is an outsider from that moment: the team is now a 404.
        $I->sendGet("/api/teams/{$teamId}");
        $I->seeResponseCodeIs(404);

        // The sole admin cannot leave (last-admin rule).
        $I->amAuthenticatedWith($admin);
        $I->sendDelete("/api/teams/{$teamId}/members/{$adminId}");
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'A team must keep at least one admin.']]]);

        // Admin removes a member -- allowed.
        $I->sendDelete("/api/teams/{$teamId}/members/{$memberId}");
        $I->seeResponseCodeIs(204);
        $I->sendGet("/api/teams/{$teamId}/members");
        $I->assertCount(1, $I->grabJsonResponse()['members']);
    }
}
