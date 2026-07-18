<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Actor for the Api suite. The methods come from the modules enabled in
 * tests/Api.suite.yml (run `vendor/bin/codecept build` after changing them).
 *
 * The have* helpers arrange fixtures through the public API itself (register,
 * issue token, create team...), so every test's setup exercises the same code
 * paths the assertions do. All test users share one password.
 */
class ApiTester extends \Codeception\Actor
{
    use _generated\ApiTesterActions;

    public const PASSWORD = 'password123';

    /** Registers a user and returns their id. */
    public function haveUser(string $email, string $displayName = 'Test User'): int
    {
        $I = $this;
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/register', [
            'email' => $email,
            'password' => self::PASSWORD,
            'displayName' => $displayName,
        ]);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['id'];
    }

    /** Exchanges credentials for a plain API token (the user must exist). */
    public function haveApiTokenFor(string $email, string $name = 'test token'): string
    {
        $I = $this;
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/tokens', [
            'email' => $email,
            'password' => self::PASSWORD,
            'name' => $name,
        ]);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['token'];
    }

    /** Registers a user AND returns a ready-to-use Bearer token. */
    public function haveUserWithToken(string $email, string $displayName = 'Test User'): string
    {
        $this->haveUser($email, $displayName);

        return $this->haveApiTokenFor($email);
    }

    /** All subsequent requests authenticate as the owner of this token. */
    public function amAuthenticatedWith(string $token): void
    {
        $this->amBearerAuthenticated($token);
        $this->haveHttpHeader('Content-Type', 'application/json');
    }

    /** Creates a team as the current user (who becomes its admin); returns the id. */
    public function haveTeam(string $name = 'Team'): int
    {
        $I = $this;
        $I->sendPost('/api/teams', ['name' => $name]);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['id'];
    }

    /** Adds a registered user to a team (current user must be an admin of it). */
    public function haveMember(int $teamId, string $email, string $role = 'member'): void
    {
        $I = $this;
        $I->sendPost("/api/teams/{$teamId}/members", ['email' => $email, 'role' => $role]);
        $I->seeResponseCodeIs(201);
    }

    /**
     * Creates a task in a team as the current user; returns the id.
     *
     * @param array<string, mixed> $fields overrides/extras (status, priority, assigneeId, dueDate, description)
     */
    public function haveTask(int $teamId, string $title = 'A task', array $fields = []): int
    {
        $I = $this;
        $I->sendPost("/api/teams/{$teamId}/tasks", ['title' => $title] + $fields);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['id'];
    }

    /** Comments on a task as the current user; returns the comment id. */
    public function haveComment(int $taskId, string $body = 'A comment'): int
    {
        $I = $this;
        $I->sendPost("/api/tasks/{$taskId}/comments", ['body' => $body]);
        $I->seeResponseCodeIs(201);

        return $I->grabJsonResponse()['id'];
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

    // --- typed wrappers over the loosely-typed module grabbers ------------
    // grabService()/grabParameter()/grabHttpHeader() return object/mixed;
    // these narrow the type (for PHPStan level 8) and fail the test loudly
    // if the container ever hands back something unexpected.

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function grabServiceTyped(string $serviceId, string $class): object
    {
        $service = $this->grabService($serviceId);
        \PHPUnit\Framework\Assert::assertInstanceOf($class, $service);
        \assert($service instanceof $class);

        return $service;
    }

    public function grabIntParameter(string $name): int
    {
        $value = $this->grabParameter($name);
        \PHPUnit\Framework\Assert::assertIsInt($value);

        return $value;
    }

    public function grabStringHttpHeader(string $name): string
    {
        $value = $this->grabHttpHeader($name);
        \PHPUnit\Framework\Assert::assertIsString($value);

        return $value;
    }
}
