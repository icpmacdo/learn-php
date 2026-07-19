<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Domain\StateConflict;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The single place where every API error becomes JSON in the one error
 * shape: {"errors": [{"field": null|string, "message": "..."}]}.
 *
 * Covers 404 (missing resource AND unknown route), 405 (wrong method), 400
 * (malformed body), 401 (missing X-Customer-Id, thrown as an HttpException),
 * 409 (any StateConflict domain exception — the pinned "valid request,
 * conflicting state" rule, mapped here because some of these cross context
 * boundaries and no single controller may legally import them), and 500
 * (anything unexpected). Controllers just throw; this listener renders.
 * Other domain exceptions that reach here uncaught are 500s on purpose:
 * mapping a domain rule to a status code is a controller decision, and an
 * unmapped one is a bug worth surfacing loudly.
 *
 * Priority -8: after Symfony's exception logger (priority 0) so errors still
 * land in the log, but before the default HTML error renderer (-128).
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

        if ($throwable instanceof StateConflict) {
            $event->setResponse(new JsonResponse(
                ['errors' => [['field' => null, 'message' => $throwable->getMessage()]]],
                Response::HTTP_CONFLICT,
            ));

            return;
        }

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = $throwable->getMessage() !== ''
                ? $throwable->getMessage()
                : (Response::$statusTexts[$status] ?? 'Error');
            $headers = $throwable->getHeaders(); // e.g. Allow: for a 405
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
