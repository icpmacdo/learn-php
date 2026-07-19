<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Builders for the project's one JSON error shape:
 * {"errors": [{"field": null|string, "message": "..."}]}.
 *
 * Non-exception error responses (422s built from violations or business
 * rules, 409 state conflicts) are created here; exceptional paths go through
 * JsonExceptionListener -- both produce the identical shape.
 */
final class ApiProblem
{
    private function __construct()
    {
    }

    public static function fromViolations(ConstraintViolationListInterface $violations): JsonResponse
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => (string) $violation->getMessage(),
            ];
        }

        return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function validationError(?string $field, string $message): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['field' => $field, 'message' => $message]]],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * 409: the request is well-formed and valid, but conflicts with current
     * state (state machine, stock, refund window) — the pinned 409-vs-422
     * distinction from the PRD.
     */
    public static function conflict(string $message): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['field' => null, 'message' => $message]]],
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * 502: an upstream dependency (the payment gateway) failed us — the
     * request was fine, our state is unchanged, the caller may retry.
     */
    public static function badGateway(string $message): JsonResponse
    {
        return new JsonResponse(
            ['errors' => [['field' => null, 'message' => $message]]],
            Response::HTTP_BAD_GATEWAY,
        );
    }
}
