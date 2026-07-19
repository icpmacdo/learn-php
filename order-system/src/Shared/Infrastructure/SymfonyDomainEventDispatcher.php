<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\DomainEventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Adapter: the DomainEventDispatcher port on top of Symfony's in-process
 * EventDispatcher. Events dispatch under their class name, so an Application
 * subscriber declares e.g. `OrderPlaced $event` and Symfony routes it —
 * synchronously, in the same PHP call stack and DB transaction as the
 * command that released the event.
 */
final class SymfonyDomainEventDispatcher implements DomainEventDispatcher
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function dispatch(DomainEvent ...$events): void
    {
        foreach ($events as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
