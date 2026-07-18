<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * The rate-limit HTTP contract: 429 in the standard error shape with a
 * Retry-After header, on the right endpoints, keyed the right way.
 *
 * The test env deliberately relaxes both limits to 10000/min (suites must
 * never flake on shared limiter state), so these tests EXHAUST the budget
 * up front by consuming tokens directly from the very limiter instance the
 * listener uses (same Redis storage, same key derivation) -- then prove the
 * whole HTTP path: listener -> exception -> JSON shape + header. Natural
 * time-based recovery is covered in the Unit suite and by
 * scripts/rate-limit-demo.sh against the dev stack (real 5/min limits).
 */
final class RateLimitCest
{
    /** BrowserKit's default client address; the listener keys auth attempts as ip:{addr}. */
    private const IP_KEY = 'ip:127.0.0.1';

    public function _after(ApiTester $I): void
    {
        // Never leak an exhausted window into other tests: limiter state
        // lives in Redis, OUTSIDE the DB transaction Codeception rolls back.
        $this->limiter($I, 'limiter.auth', self::IP_KEY)->reset();
    }

    public function authEndpointReturns429InTheErrorShapeWithRetryAfter(ApiTester $I): void
    {
        $limiter = $this->limiter($I, 'limiter.auth', self::IP_KEY);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.auth_limit'));

        // Budget spent: the next attempt -- valid or not -- is pushed back
        // before authentication even runs.
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/tokens', ['email' => 'nobody@example.com', 'password' => 'wrong', 'name' => 'x']);

        $I->seeResponseCodeIs(429);
        $I->seeHttpHeader('Retry-After');
        $retryAfter = (int) $I->grabHttpHeader('Retry-After');
        $I->assertGreaterThanOrEqual(1, $retryAfter);
        $I->assertLessThanOrEqual(60, $retryAfter);

        $body = $I->grabJsonResponse();
        $I->assertNull($body['errors'][0]['field']);
        $I->assertSame(
            sprintf('Too many requests. Retry after %d seconds.', $retryAfter),
            $body['errors'][0]['message'],
        );
    }

    public function authLimiterRecoversOnceTheWindowFrees(ApiTester $I): void
    {
        $limiter = $this->limiter($I, 'limiter.auth', self::IP_KEY);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.auth_limit'));

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/tokens', ['email' => 'nobody@example.com', 'password' => 'wrong', 'name' => 'x']);
        $I->seeResponseCodeIs(429);

        // Free the window (the deterministic stand-in for waiting out
        // Retry-After -- real elapsed-time recovery is proven by the unit
        // test and the burst script) and the same request is judged on its
        // merits again: 401 invalid credentials, not 429.
        $limiter->reset();
        $I->sendPost('/api/tokens', ['email' => 'nobody@example.com', 'password' => 'wrong', 'name' => 'x']);
        $I->seeResponseCodeIs(401);
    }

    public function browserLoginFormIsRateLimitedBeforeTheFirewall(ApiTester $I): void
    {
        $limiter = $this->limiter($I, 'limiter.auth', self::IP_KEY);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.auth_limit'));

        // Real HTTP against the form endpoint pins the listener-vs-firewall
        // ordering: onAuthEndpoint (priority 16) must fire BEFORE form_login
        // (firewall, priority 8). If that priority ever regressed to <= 8,
        // the authenticator would set a response first (302 here -- the CSRF
        // check fails the login) and stop propagation, leaving browser
        // credential stuffing unthrottled -- while the /api/tokens and
        // /api/register tests above stayed green, because no authenticator
        // responds on those paths.
        $I->sendPost('/login', ['_username' => 'nobody@example.com', '_password' => 'wrong']);

        $I->seeResponseCodeIs(429);
        $I->seeHttpHeader('Retry-After');
        // The web firewall renders Symfony's HTML error page, not the JSON
        // error shape -- JsonExceptionListener is scoped to /api.
        $I->assertStringNotContainsString('"errors"', (string) $I->grabResponse());
    }

    public function registrationSharesTheAuthBudget(ApiTester $I): void
    {
        $limiter = $this->limiter($I, 'limiter.auth', self::IP_KEY);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.auth_limit'));

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/register', [
            'email' => 'rate-limited-reg@example.com',
            'password' => ApiTester::PASSWORD,
            'displayName' => 'Never Created',
        ]);

        $I->seeResponseCodeIs(429);
    }

    public function writeEndpointsAreLimitedPerUserButReadsNever(ApiTester $I): void
    {
        $userId = $I->haveUser('rl-writer@example.com', 'RL Writer');
        $token = $I->haveApiTokenFor('rl-writer@example.com');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('RL team');

        // Exhaust THIS user's write budget (the listener keys writes user:{id}).
        $limiter = $this->limiter($I, 'limiter.api_write', 'user:'.$userId);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.api_write_limit'));

        $I->sendPost('/api/teams', ['name' => 'Blocked team']);
        $I->seeResponseCodeIs(429);
        $I->seeHttpHeader('Retry-After');
        $body = $I->grabJsonResponse();
        $I->assertNull($body['errors'][0]['field']);
        $I->assertStringStartsWith('Too many requests.', $body['errors'][0]['message']);

        // Reads are never write-limited: the same exhausted user still GETs.
        $I->sendGet('/api/teams');
        $I->seeResponseCodeIs(200);
        $I->sendGet("/api/teams/{$teamId}/tasks");
        $I->seeResponseCodeIs(200);

        // Recovery: window freed -> writes work again.
        $limiter->reset();
        $I->sendPost('/api/teams', ['name' => 'Unblocked team']);
        $I->seeResponseCodeIs(201);
    }

    public function oneUsersExhaustedWriteBudgetDoesNotTouchAnothers(ApiTester $I): void
    {
        $exhaustedId = $I->haveUser('rl-exhausted@example.com');
        $I->haveUser('rl-fresh@example.com');
        $freshToken = $I->haveApiTokenFor('rl-fresh@example.com');

        $limiter = $this->limiter($I, 'limiter.api_write', 'user:'.$exhaustedId);
        $limiter->reset();
        $limiter->consume($I->grabIntParameter('app.rate_limit.api_write_limit'));

        // Same IP (BrowserKit), different user: unaffected -- per-user keying.
        $I->amAuthenticatedWith($freshToken);
        $I->sendPost('/api/teams', ['name' => 'Fresh team']);
        $I->seeResponseCodeIs(201);

        $limiter->reset();
    }

    /** The very limiter instance the listener uses: same factory service, same key derivation. */
    private function limiter(ApiTester $I, string $factoryService, string $key): LimiterInterface
    {
        return $I->grabServiceTyped($factoryService, RateLimiterFactoryInterface::class)->create($key);
    }
}
