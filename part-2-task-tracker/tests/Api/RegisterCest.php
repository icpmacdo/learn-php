<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

final class RegisterCest
{
    public function _before(ApiTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
    }

    public function registrationReturnsThePublicUserResource(ApiTester $I): void
    {
        $I->sendPost('/api/register', [
            'email' => 'new@example.com',
            'password' => 'password123',
            'displayName' => 'Newcomer',
        ]);

        $I->seeResponseCodeIs(201);
        $body = $I->grabJsonResponse();
        $I->assertIsInt($body['id']);
        $I->assertSame('new@example.com', $body['email']);
        $I->assertSame('Newcomer', $body['displayName']);
        $I->assertArrayNotHasKey('password', $body);
        $I->assertArrayNotHasKey('passwordHash', $body);
    }

    public function duplicateEmailIs422OnTheEmailField(ApiTester $I): void
    {
        $I->haveUser('taken@example.com');

        $I->sendPost('/api/register', [
            'email' => 'taken@example.com',
            'password' => 'password123',
            'displayName' => 'Impostor',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [[
            'field' => 'email',
            'message' => 'This email is already registered.',
        ]]]);
    }

    public function invalidEmailIs422(ApiTester $I): void
    {
        $I->sendPost('/api/register', [
            'email' => 'not-an-email',
            'password' => 'password123',
            'displayName' => 'X',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'email']]]);
    }

    public function shortPasswordIs422(ApiTester $I): void
    {
        $I->sendPost('/api/register', [
            'email' => 'short@example.com',
            'password' => '1234567',
            'displayName' => 'X',
        ]);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'password']]]);
    }

    public function missingFieldsProduceOne422PerField(ApiTester $I): void
    {
        // Absent required fields behave like blank ones: per-field NotBlank
        // errors, not an opaque 400.
        $I->sendPost('/api/register', []);

        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'email']]]);
        $I->seeResponseContainsJson(['errors' => [['field' => 'password']]]);
        $I->seeResponseContainsJson(['errors' => [['field' => 'displayName']]]);
    }

    public function malformedJsonBodyIs400(ApiTester $I): void
    {
        $I->sendPost('/api/register', 'not json{');

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function nonStringFieldTypeIs400(ApiTester $I): void
    {
        // Wrong TYPE is a transport problem (400), not a validation one (422).
        $I->sendPost('/api/register', [
            'email' => 123,
            'password' => 'password123',
            'displayName' => 'X',
        ]);

        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }
}
