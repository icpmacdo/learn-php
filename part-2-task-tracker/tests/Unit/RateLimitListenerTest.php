<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\EventListener\RateLimitListener;
use Codeception\Test\Unit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * The 429 path as pure logic: the listener with in-memory limiter storage and
 * a limit of 2, per the PRD -- the exception, the Retry-After seconds and the
 * header are all asserted here, so the Api suite can keep its relaxed
 * when@test limits (deterministic suites) without losing 429 coverage.
 */
final class RateLimitListenerTest extends Unit
{
    private RateLimitListener $listener;
    private TokenStorage $tokenStorage;

    protected function _before(): void
    {
        $this->tokenStorage = new TokenStorage();
        $this->listener = new RateLimitListener(
            $this->factory('auth', 2),
            $this->factory('api_write', 2),
            $this->tokenStorage,
        );
    }

    // --- auth limiter (before-the-firewall hook) ---------------------------

    public function testThirdAuthAttemptIsRejectedWithRetryAfter(): void
    {
        $event = $this->requestEvent('POST', '/api/tokens');

        $this->listener->onAuthEndpoint($event);
        $this->listener->onAuthEndpoint($event);

        try {
            $this->listener->onAuthEndpoint($event);
            $this->fail('Expected TooManyRequestsHttpException on the third attempt');
        } catch (TooManyRequestsHttpException $e) {
            $this->assertSame(429, $e->getStatusCode());

            // Sliding window, limit 2/minute: the freed slot is at most a
            // minute away, never zero (a 0 would tell clients "retry now").
            $retryAfter = (int) $e->getHeaders()['Retry-After'];
            $this->assertGreaterThanOrEqual(1, $retryAfter);
            $this->assertLessThanOrEqual(60, $retryAfter);

            $this->assertSame(
                sprintf('Too many requests. Retry after %d seconds.', $retryAfter),
                $e->getMessage(),
            );
        }
    }

    public function testAllThreeAuthEndpointsShareOneBudget(): void
    {
        // Register, token issue and form login all count against the same
        // per-IP window -- switching endpoints must not reset the meter.
        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/api/register'));
        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/api/tokens'));

        $this->expectException(TooManyRequestsHttpException::class);
        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/login'));
    }

    public function testDifferentIpsHaveIndependentAuthBudgets(): void
    {
        $exhaust = $this->requestEvent('POST', '/login', ip: '10.0.0.1');
        $this->listener->onAuthEndpoint($exhaust);
        $this->listener->onAuthEndpoint($exhaust);

        // 10.0.0.1 is now exhausted; 10.0.0.2 must be untouched.
        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/login', ip: '10.0.0.2'));
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testGetLoginAndNonAuthPathsDoNotConsumeAuthBudget(): void
    {
        // Rendering the login form, or posting elsewhere, is not an attempt.
        for ($i = 0; $i < 5; ++$i) {
            $this->listener->onAuthEndpoint($this->requestEvent('GET', '/login'));
            $this->listener->onAuthEndpoint($this->requestEvent('POST', '/api/teams'));
        }

        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/login'));
        $this->addToAssertionCount(1);
    }

    // --- api_write limiter (after-the-firewall hook) -----------------------

    public function testThirdApiWriteIsRejectedForTheSameUser(): void
    {
        $this->authenticate($this->user(1, 'a@example.com'));

        $event = $this->requestEvent('POST', '/api/teams');
        $this->listener->onApiWrite($event);
        $this->listener->onApiWrite($this->requestEvent('PATCH', '/api/tasks/1'));

        $this->expectException(TooManyRequestsHttpException::class);
        $this->listener->onApiWrite($this->requestEvent('DELETE', '/api/comments/1'));
    }

    public function testWritesAreKeyedPerUserNotPerIp(): void
    {
        // Same IP, different users: one noisy user cannot exhaust a
        // colleague's budget (everyone behind one NAT shares an IP).
        $this->authenticate($this->user(1, 'a@example.com'));
        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));

        $this->authenticate($this->user(2, 'b@example.com'));
        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        $this->addToAssertionCount(1);
    }

    public function testWritesWithoutAnAuthenticatedUserAreNotMetered(): void
    {
        // No token in storage: in the real kernel the firewall (priority 8)
        // has already 401'd such a request before this priority-4 hook, so
        // the listener treats "no user" as "nothing to meter" and returns
        // without consuming anything -- limit 2 here, yet no throw at any
        // volume.
        for ($i = 0; $i < 5; ++$i) {
            $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        }

        // And the budget of a user who then authenticates is untouched.
        $this->authenticate($this->user(1, 'a@example.com'));
        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        $this->addToAssertionCount(1);
    }

    public function testReadsAreNeverWriteLimited(): void
    {
        $this->authenticate($this->user(1, 'a@example.com'));

        for ($i = 0; $i < 5; ++$i) {
            $this->listener->onApiWrite($this->requestEvent('GET', '/api/teams'));
        }

        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        $this->addToAssertionCount(1);
    }

    public function testAuthEndpointsAreNotDoubleChargedByTheWriteLimiter(): void
    {
        // POST /api/tokens already paid the (stricter) auth limiter at
        // priority 16; the write hook must let it through untouched.
        $this->authenticate($this->user(1, 'a@example.com'));

        for ($i = 0; $i < 5; ++$i) {
            $this->listener->onApiWrite($this->requestEvent('POST', '/api/tokens'));
        }

        $this->listener->onApiWrite($this->requestEvent('POST', '/api/teams'));
        $this->addToAssertionCount(1);
    }

    public function testSubRequestsAreIgnored(): void
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('http://localhost/api/tokens', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST);

        for ($i = 0; $i < 5; ++$i) {
            $this->listener->onAuthEndpoint($event);
        }

        // Budget untouched: a main request still passes.
        $this->listener->onAuthEndpoint($this->requestEvent('POST', '/api/tokens'));
        $this->addToAssertionCount(1);
    }

    // --- plumbing ----------------------------------------------------------

    private function factory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => $id, 'policy' => 'sliding_window', 'limit' => $limit, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );
    }

    private function requestEvent(string $method, string $path, string $ip = '127.0.0.1'): RequestEvent
    {
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('http://localhost'.$path, $method, server: ['REMOTE_ADDR' => $ip]);

        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function user(int $id, string $email): User
    {
        $user = new User($email, 'User '.$id);

        // In-memory entities have null ids; the listener keys on the id, so
        // plant one the way the ORM would.
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    private function authenticate(User $user): void
    {
        $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));
    }
}
