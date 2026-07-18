<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class DeleteLinkCest
{
    public function deleteReturns204AndTheLinkIsGone(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/to-delete');

        $I->sendDelete('/links/'.$code);
        $I->seeResponseCodeIs(204);
        $I->assertSame('', $I->grabResponse(), '204 body is empty');

        $I->sendGet('/links/'.$code);
        $I->seeResponseCodeIs(404);
    }

    public function deletingAnUnknownCodeIs404(ApiTester $I): void
    {
        $I->sendDelete('/links/zzzzzzz');

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Link not found.']]]);
    }

    public function secondDeleteOfTheSameCodeIs404(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/delete-twice');

        $I->sendDelete('/links/'.$code);
        $I->seeResponseCodeIs(204);

        $I->sendDelete('/links/'.$code);
        $I->seeResponseCodeIs(404);
    }

    public function nonAsciiCodeIs404NotA500(ApiTester $I): void
    {
        // Same guarantee as GET: a non-base62 path segment is a routing-level
        // 404, never a 500 from the ascii_bin `code` column comparison.
        $I->sendDelete('/links/h%C3%A9llo'); // "héllo"

        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function deletedCodeNoLongerRedirects(ApiTester $I): void
    {
        $code = $I->haveLink('https://example.com/dead-redirect');

        $I->sendDelete('/links/'.$code);
        $I->seeResponseCodeIs(204);

        $I->stopFollowingRedirects();
        $I->sendGet('/r/'.$code);
        $I->seeResponseCodeIs(404);
    }
}
