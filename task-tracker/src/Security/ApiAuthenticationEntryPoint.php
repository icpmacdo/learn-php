<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * What an UNAUTHENTICATED request to a protected /api route gets.
 *
 * The web firewall's equivalent is the 302 redirect to /login; an API client
 * can't follow a login form, so the API answers 401 in the standard error
 * shape. (This is the "entry point": how a firewall *starts* authentication.)
 */
final class ApiAuthenticationEntryPoint implements AuthenticationEntryPointInterface
{
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new JsonResponse(
            ['errors' => [['field' => null, 'message' => 'Authentication required.']]],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }
}
