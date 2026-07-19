<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Thrown when two Money values of different currencies meet in one operation.
 * There is no exchange rate in this domain; mixing currencies is always a bug
 * or a rejected request (422 "Cart currency mismatch." at the HTTP edge).
 */
final class CurrencyMismatch extends \DomainException
{
}
