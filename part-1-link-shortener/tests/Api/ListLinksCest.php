<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class ListLinksCest
{
    public function emptyDatabaseGivesEmptyList(ApiTester $I): void
    {
        $I->sendGet('/links');

        $I->seeResponseCodeIs(200);
        $I->seeResponseIsJson();
        $I->assertSame(['links' => []], $I->grabJsonResponse());
    }

    public function listsLinksNewestFirstWithHitCounts(ApiTester $I): void
    {
        $first = $I->haveLink('https://example.com/first');
        $second = $I->haveLink('https://example.com/second');
        $third = $I->haveLink('https://example.com/third');

        // Give one link a hit so the count shows up in the list.
        $I->stopFollowingRedirects();
        $I->sendGet('/r/'.$second);
        $I->seeResponseCodeIs(302);

        $I->sendGet('/links');
        $I->seeResponseCodeIs(200);

        $links = $I->grabJsonResponse()['links'];
        $I->assertCount(3, $links);
        $I->assertSame([$third, $second, $first], array_column($links, 'code'), 'newest first');

        $byCode = array_column($links, null, 'code');
        $I->assertSame(1, $byCode[$second]['hits']);
        $I->assertSame(0, $byCode[$first]['hits']);
        $I->assertSame(0, $byCode[$third]['hits']);
    }
}
