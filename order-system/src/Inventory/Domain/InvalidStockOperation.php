<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Guard for release()/commit(): touching more stock than is reserved is
 * always a programming error (the Reservation aggregate's once-only status
 * guard should make it unreachable), so this is a loud failure, not a 4xx.
 */
final class InvalidStockOperation extends \DomainException
{
}
