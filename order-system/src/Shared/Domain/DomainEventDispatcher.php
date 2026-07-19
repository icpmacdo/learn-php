<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Port: hand released domain events to whoever consumes them.
 *
 * In part 3 the adapter is Symfony's in-process EventDispatcher and every
 * subscriber runs synchronously inside the command's DB transaction — that is
 * what makes "reserve stock or roll the whole checkout back" possible. Part 4
 * deliberately breaks this guarantee (outbox, async consumers) — the port
 * stays, the adapter changes.
 */
interface DomainEventDispatcher
{
    public function dispatch(DomainEvent ...$events): void;
}
