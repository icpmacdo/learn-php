<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class CreateLinkCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function createReturnsTheLinkResource(ApiTester $I): void
    {
        $I->sendPost('/links', ['url' => 'https://example.com/some/long/path']);

        $I->seeResponseCodeIs(201);
        $I->seeResponseIsJson();

        $body = $I->grabJsonResponse();
        $I->assertMatchesRegularExpression('/^[0-9A-Za-z]{7}$/', $body['code'], 'code is 7 base62 chars');
        $I->assertSame('https://example.com/some/long/path', $body['url']);
        $I->assertStringEndsWith('/r/'.$body['code'], $body['shortUrl']);
        $I->assertSame(0, $body['hits']);
        // createdAt is ATOM / RFC 3339 in UTC
        $I->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $body['createdAt']);

        $I->seeHttpHeader('Location', '/links/'.$body['code']);
    }

    public function blankUrlIsRejectedWith422(ApiTester $I): void
    {
        $I->sendPost('/links', ['url' => '']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'url']]]);
    }

    public function invalidUrlIsRejectedWith422(ApiTester $I): void
    {
        $I->sendPost('/links', ['url' => 'not a url']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'url', 'message' => 'This value is not a valid URL.']]]);
    }

    public function nonHttpSchemeIsRejectedWith422(ApiTester $I): void
    {
        $I->sendPost('/links', ['url' => 'ftp://example.com/file.txt']);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'url']]]);
    }

    public function urlLongerThan2048CharsIsRejectedWith422(ApiTester $I): void
    {
        $url = 'https://example.com/'.str_repeat('a', 2049);
        $I->sendPost('/links', ['url' => $url]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'url']]]);
    }

    public function malformedJsonBodyIs400(ApiTester $I): void
    {
        $I->sendPost('/links', 'this is not json{');

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [[
            'field' => null,
            'message' => 'Request body must be valid JSON with a "url" field.',
        ]]]);
    }

    public function missingUrlKeyIs400(ApiTester $I): void
    {
        $I->sendPost('/links', ['target' => 'https://example.com']);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function numericUrlValueIs400(ApiTester $I): void
    {
        // "url" present but not a string: a malformed request (400), not a
        // validation failure (422) -- pins the is_string guard in the
        // controller, without which this would be a TypeError-driven 500.
        $I->sendPost('/links', ['url' => 123]);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [[
            'field' => null,
            'message' => 'Request body must be valid JSON with a "url" field.',
        ]]]);
    }

    public function nullUrlValueIs400(ApiTester $I): void
    {
        $I->sendPost('/links', ['url' => null]);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function duplicateUrlsGetDistinctCodes(ApiTester $I): void
    {
        $codeA = $I->haveLink('https://example.com/same-target');
        $codeB = $I->haveLink('https://example.com/same-target');

        $I->assertNotSame($codeA, $codeB, 'posting the same URL twice creates two links');
    }
}
