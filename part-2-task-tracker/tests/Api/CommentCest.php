<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * Comment matrix: any member comments; editing is author-ONLY (admins
 * included in the ban); deletion is author-or-admin.
 */
final class CommentCest
{
    public function memberCommentsOnATask(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('cc-admin@example.com');
        $I->haveUser('cc-member@example.com', 'Chatty');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Chatting');
        $taskId = $I->haveTask($teamId, 'Discussable');
        $I->haveMember($teamId, 'cc-member@example.com');

        $member = $I->haveApiTokenFor('cc-member@example.com');
        $I->amAuthenticatedWith($member);
        $I->sendPost("/api/tasks/{$taskId}/comments", ['body' => 'On it.']);

        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'taskId' => $taskId,
            'body' => 'On it.',
            'author' => ['email' => 'cc-member@example.com'],
        ]);
    }

    public function blankOrOversizedBodyIs422(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('cval@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Strict comments');
        $taskId = $I->haveTask($teamId);

        $I->sendPost("/api/tasks/{$taskId}/comments", ['body' => '']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'body']]]);

        $I->sendPost("/api/tasks/{$taskId}/comments", ['body' => str_repeat('a', 5001)]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'body']]]);
    }

    public function editingIsAuthorOnlyEvenAdminsCannot(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('ce-admin@example.com');
        $I->haveUser('ce-author@example.com');
        $I->haveUser('ce-other@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Editorial');
        $taskId = $I->haveTask($teamId);
        $I->haveMember($teamId, 'ce-author@example.com');
        $I->haveMember($teamId, 'ce-other@example.com');

        $author = $I->haveApiTokenFor('ce-author@example.com');
        $I->amAuthenticatedWith($author);
        $commentId = $I->haveComment($taskId, 'my words');

        // Another member: 403.
        $other = $I->haveApiTokenFor('ce-other@example.com');
        $I->amAuthenticatedWith($other);
        $I->sendPatch("/api/comments/{$commentId}", ['body' => 'their words']);
        $I->seeResponseCodeIs(403);

        // Even the team ADMIN cannot edit: moderation is delete, not rewrite.
        $I->amAuthenticatedWith($admin);
        $I->sendPatch("/api/comments/{$commentId}", ['body' => 'admin words']);
        $I->seeResponseCodeIs(403);

        // The author can.
        $I->amAuthenticatedWith($author);
        $I->sendPatch("/api/comments/{$commentId}", ['body' => 'my edited words']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['body' => 'my edited words']);
    }

    public function deletionIsAuthorOrAdmin(ApiTester $I): void
    {
        $admin = $I->haveUserWithToken('cd-admin@example.com');
        $I->haveUser('cd-author@example.com');
        $I->haveUser('cd-other@example.com');
        $I->amAuthenticatedWith($admin);
        $teamId = $I->haveTeam('Moderated');
        $taskId = $I->haveTask($teamId);
        $I->haveMember($teamId, 'cd-author@example.com');
        $I->haveMember($teamId, 'cd-other@example.com');

        $author = $I->haveApiTokenFor('cd-author@example.com');
        $I->amAuthenticatedWith($author);
        $commentA = $I->haveComment($taskId, 'A');
        $commentB = $I->haveComment($taskId, 'B');

        // A non-author member: 403.
        $other = $I->haveApiTokenFor('cd-other@example.com');
        $I->amAuthenticatedWith($other);
        $I->sendDelete("/api/comments/{$commentA}");
        $I->seeResponseCodeIs(403);

        // The author: 204.
        $I->amAuthenticatedWith($author);
        $I->sendDelete("/api/comments/{$commentA}");
        $I->seeResponseCodeIs(204);

        // The admin (moderation): 204.
        $I->amAuthenticatedWith($admin);
        $I->sendDelete("/api/comments/{$commentB}");
        $I->seeResponseCodeIs(204);
    }

    public function outsiderGets404ForComments(ApiTester $I): void
    {
        $owner = $I->haveUserWithToken('co-owner@example.com');
        $I->amAuthenticatedWith($owner);
        $teamId = $I->haveTeam('Private thread');
        $taskId = $I->haveTask($teamId);
        $commentId = $I->haveComment($taskId, 'secret');

        $outsider = $I->haveUserWithToken('co-outsider@example.com');
        $I->amAuthenticatedWith($outsider);

        $I->sendPatch("/api/comments/{$commentId}", ['body' => 'hijack']);
        $I->seeResponseCodeIs(404);
        $I->sendDelete("/api/comments/{$commentId}");
        $I->seeResponseCodeIs(404);
    }
}
