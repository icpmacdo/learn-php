<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Builders for the project's one JSON error shape:
 * {"errors": [{"field": null|string, "message": "..."}]}.
 *
 * Non-exception error responses (422s built from violations or business
 * rules) are created here; exceptional paths go through JsonExceptionListener
 * -- both produce the identical shape.
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
}
