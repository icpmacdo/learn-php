<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * The error contract holds everywhere, not just on happy-path routes:
 * one kernel.exception listener, one JSON error shape.
 */
final class CrossCuttingCest
{
    public function unknownRouteIs404InTheJsonErrorShape(ApiTester $I): void
    {
        $I->sendGet('/definitely/not/a/route');

        $I->seeResponseCodeIs(404);
        $I->seeResponseIsJson();

        $body = $I->grabJsonResponse();
        $I->assertArrayHasKey('errors', $body);
        $I->assertNotEmpty($body['errors']);
        $I->assertNull($body['errors'][0]['field']);
        $I->assertIsString($body['errors'][0]['message']);
    }

    public function wrongMethodOnAKnownRouteIs405InTheJsonErrorShape(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/links', ['url' => 'https://example.com']);

        $I->seeResponseCodeIs(405);
        $I->seeResponseIsJson();

        $body = $I->grabJsonResponse();
        $I->assertNull($body['errors'][0]['field']);
        $I->assertIsString($body['errors'][0]['message']);
    }
}
