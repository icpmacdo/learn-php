<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Port: "what time is it?".
 *
 * The refund window (paid orders cancellable for 24h) is a time-based domain
 * rule; hard-coding `new \DateTimeImmutable()` inside the Order aggregate
 * would make that rule untestable. Domain code asks this port; production
 * wires SystemClock, unit tests wire a fixed clock and time-travel freely.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
