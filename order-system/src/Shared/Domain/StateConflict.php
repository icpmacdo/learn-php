<?php

declare(strict_types=1);

namespace App\Shared\Domain;

/**
 * Marker for domain exceptions that mean "the request is valid, but it
 * conflicts with current state" — the PRD's pinned 409 rule (state machine,
 * stock, refund window), as opposed to 422 (the request itself is invalid).
 *
 * Why a shared marker instead of per-controller catch blocks: some of these
 * exceptions cross context boundaries (Inventory's InsufficientStock erupts
 * through Ordering's checkout), and an Ordering controller importing
 * Inventory\Domain would violate the deptrac law. Implementing a SHARED
 * interface keeps the dependency arrows legal: each Domain depends only on
 * SharedDomain, and the one kernel.exception listener maps any StateConflict
 * to a 409 without knowing which context threw it.
 */
interface StateConflict extends \Throwable
{
}
