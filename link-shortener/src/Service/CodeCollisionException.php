<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Thrown when LinkCreator exhausts its retry budget. Not an HttpException,
 * so JsonExceptionListener renders it as a 500.
 */
final class CodeCollisionException extends \RuntimeException
{
}
