<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Actor for the Api suite. The methods come from the modules enabled in
 * tests/Api.suite.yml (run `vendor/bin/codecept build` after changing them).
 */
class ApiTester extends \Codeception\Actor
{
    use _generated\ApiTesterActions;

    /**
     * Creates a link over the API and returns its short code.
     */
    public function haveLink(string $url): string
    {
        $I = $this;
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/links', ['url' => $url]);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['code'];
    }

    /**
     * @return array<string, mixed> decoded JSON response body
     */
    public function grabJsonResponse(): array
    {
        $body = json_decode($this->grabResponse(), true);
        \PHPUnit\Framework\Assert::assertIsArray($body, 'Response body is not valid JSON');

        return $body;
    }
}
