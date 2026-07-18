<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class TokenCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function issuingReturnsThePlainTokenExactlyOnce(ApiTester $I): void
    {
        $I->haveUser('owner@example.com');

        $I->sendPost('/api/tokens', [
            'email' => 'owner@example.com',
            'password' => ApiTester::PASSWORD,
            'name' => 'laptop curl',
        ]);

        $I->seeResponseCodeIs(201);
        $body = $I->grabJsonResponse();
        $I->assertMatchesRegularExpression('/^tt2_[0-9a-f]{64}$/', $body['token']);
        $I->assertSame('laptop curl', $body['name']);
        $I->assertIsInt($body['id']);

        // ... and the listing never shows it again.
        $I->amAuthenticatedWith($body['token']);
        $I->sendGet('/api/tokens');
        $I->seeResponseCodeIs(200);
        $list = $I->grabJsonResponse();
        $I->assertCount(1, $list['tokens']);
        $I->assertArrayNotHasKey('token', $list['tokens'][0]);
        $I->assertSame('laptop curl', $list['tokens'][0]['name']);
        $I->assertNotNull($list['tokens'][0]['lastUsedAt'], 'authenticating touched lastUsedAt');
    }

    public function unknownEmailAndWrongPasswordAreIndistinguishable401s(ApiTester $I): void
    {
        $I->haveUser('exists@example.com');

        $I->sendPost('/api/tokens', ['email' => 'ghost@example.com', 'password' => 'password123', 'name' => 'x']);
        $I->seeResponseCodeIs(401);
        $unknownEmailBody = $I->grabResponse();

        $I->sendPost('/api/tokens', ['email' => 'exists@example.com', 'password' => 'wrong-password', 'name' => 'x']);
        $I->seeResponseCodeIs(401);

        // Byte-identical bodies: no user-enumeration oracle.
        $I->assertSame($unknownEmailBody, $I->grabResponse());
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Invalid credentials.']]]);
    }

    public function missingNameIs422(ApiTester $I): void
    {
        $I->haveUser('nameless@example.com');

        $I->sendPost('/api/tokens', ['email' => 'nameless@example.com', 'password' => ApiTester::PASSWORD]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'name']]]);
    }

    public function aTokenAuthenticatesApiRequests(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('me@example.com', 'Me Myself');

        $I->amAuthenticatedWith($token);
        $I->sendGet('/api/me');

        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['email' => 'me@example.com', 'displayName' => 'Me Myself']);
    }

    public function missingTokenIs401(ApiTester $I): void
    {
        $I->sendGet('/api/me');

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Authentication required.']]]);
    }

    public function garbageTokenIs401(ApiTester $I): void
    {
        $I->amAuthenticatedWith('tt2_'.str_repeat('0', 64));
        $I->sendGet('/api/me');

        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Invalid API token.']]]);
    }

    public function revokingATokenKillsItImmediately(ApiTester $I): void
    {
        $I->haveUser('revoker@example.com');
        $keeper = $I->haveApiTokenFor('revoker@example.com', 'keeper');
        $victim = $I->haveApiTokenFor('revoker@example.com', 'victim');

        // Find the victim's id via the listing.
        $I->amAuthenticatedWith($keeper);
        $I->sendGet('/api/tokens');
        $tokens = $I->grabJsonResponse()['tokens'];
        $I->assertCount(2, $tokens);
        $victimId = null;
        foreach ($tokens as $t) {
            if ($t['name'] === 'victim') {
                $victimId = $t['id'];
            }
        }

        $I->sendDelete("/api/tokens/{$victimId}");
        $I->seeResponseCodeIs(204);

        // The very next request with the revoked token is a 401.
        $I->amAuthenticatedWith($victim);
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(401);
    }

    public function someoneElsesTokenIdIs404(ApiTester $I): void
    {
        $aliceToken = $I->haveUserWithToken('alice-tokens@example.com');
        $I->haveUser('bob-tokens@example.com');
        $bobToken = $I->haveApiTokenFor('bob-tokens@example.com');

        $I->amAuthenticatedWith($bobToken);
        $I->sendGet('/api/tokens');
        $bobTokenId = $I->grabJsonResponse()['tokens'][0]['id'];

        // Alice cannot revoke (or even detect) Bob's token.
        $I->amAuthenticatedWith($aliceToken);
        $I->sendDelete("/api/tokens/{$bobTokenId}");
        $I->seeResponseCodeIs(404);

        // Bob's token still works.
        $I->amAuthenticatedWith($bobToken);
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(200);
    }
}
