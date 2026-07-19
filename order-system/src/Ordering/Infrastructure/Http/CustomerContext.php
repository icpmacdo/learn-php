<?php

declare(strict_types=1);

namespace App\Ordering\Infrastructure\Http;

use App\Shared\Domain\CustomerId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The whole of part 3's "auth": X-Customer-Id, taken at face value (real
 * auth was part 2's lesson; repeating it would dilute the domain focus).
 * Missing, blank or over-long -> 401 in the standard error shape (via the
 * kernel.exception listener). Customer endpoints call this first; admin-ish
 * endpoints never call it.
 */
final class CustomerContext
{
    private function __construct()
    {
    }

    public static function requireCustomer(Request $request): CustomerId
    {
        $raw = trim((string) $request->headers->get('X-Customer-Id', ''));
        if ($raw === '' || mb_strlen($raw) > 64) {
            throw new HttpException(Response::HTTP_UNAUTHORIZED, 'Missing or invalid X-Customer-Id header.');
        }

        return CustomerId::fromString($raw);
    }
}
