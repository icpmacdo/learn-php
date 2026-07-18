<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The single place where every API error becomes JSON in the one error
 * shape: {"errors": [{"field": null|string, "message": "..."}]}.
 *
 * Covers 404 (missing resource AND unknown route), 405 (wrong method), 400
 * (malformed body), 403 (voter denial -- AccessDeniedException is not an
 * HttpException, so it is mapped here), and 500 (anything unexpected).
 * Controllers just throw; this listener renders.
 *
 * Scoped to /api paths: the Twig pages (/login, /dashboard) keep Symfony's
 * HTML error handling -- a browser user should never see raw JSON.
 *
 * Priority -8: after Symfony's exception logger (priority 0) so errors still
 * land in the log, but before the default HTML error renderer (-128).
 * (Security's own listener runs at priority 1: 401s/entry-point redirects are
 * already handled before this listener ever sees them.)
 */
#[AsEventListener(event: 'kernel.exception', priority: -8)]
final class JsonExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = $throwable->getMessage() !== ''
                ? $throwable->getMessage()
                : (Response::$statusTexts[$status] ?? 'Error');
            $headers = $throwable->getHeaders(); // e.g. Allow: for a 405
        } elseif ($throwable instanceof AccessDeniedException) {
            // A voter said no to an authenticated user. Note the policy: this
            // only ever reaches members (outsiders were 404'd by the *_VIEW
            // gate first), so a 403 leaks nothing about other teams.
            $status = Response::HTTP_FORBIDDEN;
            $message = 'Access denied.';
            $headers = [];
        } else {
            // Unhandled exception: generic 500, never a stack trace.
            $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            $message = 'Internal server error.';
            $headers = [];
        }

        $event->setResponse(new JsonResponse(
            ['errors' => [['field' => null, 'message' => $message]]],
            $status,
            $headers,
        ));
    }
}
