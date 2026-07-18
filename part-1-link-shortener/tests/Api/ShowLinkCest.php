<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class ShowLinkCest
{
    public function showsAKnownLink(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/details');

        $I->sendGet('/links/'.$code);

        $I->seeResponseCodeIs(200);
        $body = $I->grabJsonResponse();
        $I->assertSame($code, $body['code']);
        $I->assertSame('https://example.com/details', $body['url']);
        $I->assertStringEndsWith('/r/'.$code, $body['shortUrl']);
        $I->assertSame(0, $body['hits']);
        $I->assertArrayHasKey('createdAt', $body);
    }

    public function unknownCodeIs404WithErrorShape(ApiTester $I): void
    {
        $I->sendGet('/links/zzzzzzz');

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Link not found.']]]);
    }

    public function nonAsciiCodeIs404NotA500(ApiTester $I): void
    {
        // A short URL mangled in transit (smart quote, accent, ellipsis) must
        // be an ordinary 404, not a 500 from comparing non-ASCII input
        // against the ascii_bin `code` column. The route requirement
        // ([0-9A-Za-z]+) rejects it at the router.
        $I->sendGet('/links/h%C3%A9llo'); // "héllo"

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }
}
