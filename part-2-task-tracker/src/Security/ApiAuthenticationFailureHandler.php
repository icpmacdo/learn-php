<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * What a request presenting an INVALID/REVOKED Bearer token gets: the same
 * 401 shape as everywhere else (Symfony's default would be a bodyless 401
 * with only a WWW-Authenticate header).
 *
 * The message is deliberately generic -- never reveal whether the token was
 * malformed, unknown, or revoked.
 */
final class ApiAuthenticationFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['errors' => [['field' => null, 'message' => 'Invalid API token.']]],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
