<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Enforces the two named limiters from config/packages/rate_limiter.yaml on
 * kernel.request. One listener class, TWO hooks at different priorities,
 * because the two limiters need opposite positions relative to the security
 * firewall (which listens at priority 8):
 *
 *  - auth (priority 16, BEFORE the firewall): keyed by client IP -- no user
 *    needed -- and it MUST run first, because on POST /login the form_login
 *    authenticator handles the request and sets a response itself; a listener
 *    behind the firewall would never see the attempt. Running before the
 *    firewall is also what makes FAILED logins count (credential stuffing is
 *    the attack being modeled).
 *
 *  - api_write (priority 4, AFTER the firewall): keyed by the authenticated
 *    user id, which only exists once the firewall has run. One user hammering
 *    writes cannot exhaust anyone else's budget (an IP key would lump
 *    everyone behind one NAT together).
 *
 * On rejection: TooManyRequestsHttpException, which JsonExceptionListener
 * renders as a 429 in the standard error shape; the Retry-After header rides
 * on the exception itself. (On the web firewall -- POST /login -- the same
 * exception falls through to Symfony's HTML error page, Retry-After intact:
 * a browser user should never see raw JSON.)
 */
#[AsEventListener(event: 'kernel.request', method: 'onAuthEndpoint', priority: 16)]
#[AsEventListener(event: 'kernel.request', method: 'onApiWrite', priority: 4)]
final class RateLimitListener
{
    /** The three credential-accepting endpoints share the auth budget. */
    private const AUTH_PATHS = ['/api/register', '/api/tokens', '/login'];

    public function __construct(
        private readonly RateLimiterFactoryInterface $authLimiter,
        private readonly RateLimiterFactoryInterface $apiWriteLimiter,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function onAuthEndpoint(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getMethod() !== Request::METHOD_POST
            || !\in_array($request->getPathInfo(), self::AUTH_PATHS, true)) {
            return;
        }

        $this->consumeOrThrow($this->authLimiter->create('ip:'.$this->clientIp($request)));
    }

    public function onApiWrite(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!\in_array($request->getMethod(), [Request::METHOD_POST, Request::METHOD_PATCH, Request::METHOD_DELETE], true)
            || !str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // The auth endpoints already consumed from the (stricter) auth
        // limiter at priority 16 -- charging them twice would be unfair.
        if (\in_array($request->getPathInfo(), self::AUTH_PATHS, true)) {
            return;
        }

        // No authenticated user can only mean the request never made it
        // through the firewall -- and then it never gets here: the firewall
        // (priority 8) stops kernel.request propagation with a 401 before
        // this priority-4 hook runs. The only unauthenticated /api writes
        // that DO reach this hook are the PUBLIC_ACCESS auth endpoints,
        // and those returned above. So: nothing to meter without a user.
        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->consumeOrThrow($this->apiWriteLimiter->create('user:'.$user->getId()));
    }

    private function consumeOrThrow(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume();
        if ($limit->isAccepted()) {
            return;
        }

        $seconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        // The int $seconds becomes the Retry-After header; the message becomes
        // the standard-shape JSON body via JsonExceptionListener.
        throw new TooManyRequestsHttpException($seconds, sprintf('Too many requests. Retry after %d seconds.', $seconds));
    }

    private function clientIp(Request $request): string
    {
        return $request->getClientIp() ?? 'unknown';
    }
}
