<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reading a JSON request body, split into the two failure layers parts 1-2
 * established:
 *
 *   400 -- the body is not usable at all (malformed JSON, wrong TYPE for a
 *          field): a transport problem.
 *   422 -- the body is well-formed but a VALUE is invalid: handled later by
 *          the Validator on a DTO (absent / null / "" string fields all reach
 *          NotBlank as "", so required-field errors are per-field 422s).
 */
final class JsonBody
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function decode(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        return $body;
    }

    /**
     * Required string field: absent or null becomes "" (so NotBlank produces
     * the 422), any non-string type is a 400.
     *
     * @param array<string, mixed> $body
     */
    public static function string(array $body, string $key): string
    {
        $value = $body[$key] ?? null;
        if ($value === null) {
            return '';
        }
        if (!\is_string($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * Optional string field: absent or null is null, any non-string is a 400.
     *
     * @param array<string, mixed> $body
     */
    public static function optionalString(array $body, string $key): ?string
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * Optional integer field: absent or null is null, anything but a JSON
     * integer (no numeric strings, no floats) is a 400. Required-int DTO
     * fields pair this with #[Assert\NotNull] so "absent" is a per-field 422.
     *
     * @param array<string, mixed> $body
     */
    public static function optionalInt(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !\is_int($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be an integer.', $key));
        }

        return $value;
    }

    /**
     * Optional boolean field: absent or null is null, anything but a JSON
     * boolean is a 400.
     *
     * @param array<string, mixed> $body
     */
    public static function optionalBool(array $body, string $key): ?bool
    {
        $value = $body[$key] ?? null;
        if ($value !== null && !\is_bool($value)) {
            throw new BadRequestHttpException(sprintf('Field "%s" must be a boolean.', $key));
        }

        return $value;
    }
}
