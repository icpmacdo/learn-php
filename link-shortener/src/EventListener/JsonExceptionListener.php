<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * The single place where every error becomes JSON in the API's one error
 * shape: {"errors": [{"field": null|string, "message": "..."}]}.
 *
 * Covers 404 (unknown code AND unknown route), 405 (wrong method), 400
 * (malformed body), and 500 (anything unexpected). Controllers just throw
 * HttpExceptions; this listener renders them.
 *
 * Priority -8: after Symfony's exception logger (priority 0) so errors still
 * land in the log, but before the default HTML error renderer (-128).
 */
#[AsEventListener(event: 'kernel.exception', priority: -8)]
final class JsonExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();

        if ($throwable instanceof HttpExceptionInterface) {
            $status = $throwable->getStatusCode();
            $message = $throwable->getMessage() !== ''
                ? $throwable->getMessage()
                : (Response::$statusTexts[$status] ?? 'Error');
            $headers = $throwable->getHeaders(); // e.g. Allow: for a 405
        } else {
            // Unhandled exception: generic 500, never a stack trace.
            // Details land in var/log/dev.log (monolog) in the dev env.
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
