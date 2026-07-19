<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Aggregate-side half of the event seam: aggregates record facts as they
 * mutate; the Application handler releases them after persisting and hands
 * them to the DomainEventDispatcher port. The aggregate never dispatches —
 * it doesn't know dispatch exists.
 */
trait RecordsEvents
{
    /** @var list<DomainEvent> */
    private array $recordedEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
