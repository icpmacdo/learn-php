<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * Cross-cutting HTTP semantics: every error is the one shape, 401 blankets
 * the whole protected surface, route requirements reject junk ids.
 */
final class CrossCuttingCest
{
    public function everyProtectedRouteIs401WithoutAToken(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');

        $routes = [
            ['GET', '/api/me'],
            ['GET', '/api/tokens'],
            ['DELETE', '/api/tokens/1'],
            ['GET', '/api/teams'],
            ['POST', '/api/teams'],
            ['GET', '/api/teams/1'],
            ['PATCH', '/api/teams/1'],
            ['DELETE', '/api/teams/1'],
            ['GET', '/api/teams/1/members'],
            ['POST', '/api/teams/1/members'],
            ['PATCH', '/api/teams/1/members/1'],
            ['DELETE', '/api/teams/1/members/1'],
            ['GET', '/api/teams/1/tasks'],
            ['POST', '/api/teams/1/tasks'],
            ['GET', '/api/tasks/1'],
            ['PATCH', '/api/tasks/1'],
            ['DELETE', '/api/tasks/1'],
            ['POST', '/api/tasks/1/comments'],
            ['PATCH', '/api/comments/1'],
            ['DELETE', '/api/comments/1'],
        ];

        foreach ($routes as [$method, $uri]) {
            match ($method) {
                'GET' => $I->sendGet($uri),
                'POST' => $I->sendPost($uri, []),
                'PATCH' => $I->sendPatch($uri, []),
                'DELETE' => $I->sendDelete($uri),
            };
            $I->seeResponseCodeIs(401);
            $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
        }
    }

    public function unknownApiRouteIs404InTheErrorShape(ApiTester $I): void
    {
        $I->sendGet('/api/nope');

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function wrongMethodIs405InTheErrorShape(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/me', []);

        $I->seeResponseCodeIs(405);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
        $I->seeHttpHeader('Allow', 'GET');
    }

    public function nonNumericIdsAreRoutingLevel404s(ApiTester $I): void
    {
        // The \d+ route requirements reject junk before any controller or DB
        // work -- part 1's non-ASCII-input lesson carried forward.
        $token = $I->haveUserWithToken('junk-ids@example.com');
        $I->amAuthenticatedWith($token);

        foreach (['/api/tasks/abc', '/api/teams/1x', '/api/teams/%C3%A9', '/api/comments/-1'] as $uri) {
            $I->sendGet($uri);
            $I->seeResponseCodeIs(404);
        }
    }
}
