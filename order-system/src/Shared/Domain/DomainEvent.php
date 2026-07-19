<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Marker interface for domain events — past-tense facts recorded by
 * aggregates ("OrderPlaced", "StockReserved") and dispatched after the
 * aggregate is persisted. Events are the published language between contexts:
 * the only thing Inventory ever learns about Ordering is what these carry.
 */
interface DomainEvent
{
}
