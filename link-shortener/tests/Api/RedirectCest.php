<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class RedirectCest
{
    public function _before(ApiTester $I): void
    {
        // We assert on the 302 itself, so the client must not auto-follow it.
        $I->stopFollowingRedirects();
    }

    public function redirects302ToTheStoredUrlAndIncrementsHits(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/target-page');

        $I->sendGet('/r/'.$code);
        $I->seeResponseCodeIs(302);
        $I->seeHttpHeader('Location', 'https://example.com/target-page');

        // The hit count actually changed (asserted through the API).
        $I->sendGet('/links/'.$code);
        $I->seeResponseCodeIs(200);
        $I->assertSame(1, $I->grabJsonResponse()['hits'], 'one redirect -> hits = 1');
    }

    public function everyRedirectCounts(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/popular');

        $I->sendGet('/r/'.$code);
        $I->seeResponseCodeIs(302);
        $I->sendGet('/r/'.$code);
        $I->seeResponseCodeIs(302);

        $I->sendGet('/links/'.$code);
        $I->assertSame(2, $I->grabJsonResponse()['hits'], 'two redirects -> hits = 2');
    }

    public function unknownCodeIs404WithJsonErrorShape(ApiTester $I): void
    {
        $I->sendGet('/r/zzzzzzz');

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Link not found.']]]);
    }

    public function nonAsciiCodeIs404NotA500(ApiTester $I): void
    {
        // Mangled short URL (non-base62 character) -> routing-level 404,
        // never a 500 from the ascii_bin `code` column comparison.
        $I->sendGet('/r/h%C3%A9llo'); // "héllo"

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }
}
