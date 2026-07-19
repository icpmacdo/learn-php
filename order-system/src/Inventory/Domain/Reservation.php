<?php

declare(strict_types=1);

namespace App\Inventory\Domain;

/**
 * Stock held against one placed order until the order ships (commit) or is
 * cancelled (release).
 *
 * Invariants (PRD R1-R2):
 *   R1  exactly one reservation per order — the unique index on order_id is
 *       the enforcement (no racy pre-check).
 *   R2  a reservation is released or committed AT MOST ONCE: the status
 *       guard makes the release/commit subscribers naturally idempotent-
 *       hostile — this is the seed of part 4's idempotency lesson, where the
 *       same events may arrive twice.
 *
 * The order id is a plain string on purpose: it crosses the context boundary
 * as a value; Inventory never imports Ordering's OrderId type.
 *
 * Emits no events itself — the StockItem events (reserved/released/
 * committed, each carrying `available`) already tell the story projectors
 * need; a parallel reservation-event stream would be ceremony.
 */
class Reservation
{
    private ReservationId $id;
    private string $orderId;
    private ReservationStatus $status;
    /** @var iterable<int, ReservationLine> */
    private iterable $lines;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    /** @param list<NewReservationLine> $lines */
    private function __construct(string $orderId, array $lines, \DateTimeImmutable $now)
    {
        if ($lines === []) {
            throw new \DomainException('A reservation must hold at least one line.');
        }

        $lineEntities = [];
        foreach ($lines as $line) {
            $lineEntities[] = new ReservationLine($this, $line->sku, $line->quantity);
        }

        $this->id = ReservationId::generate();
        $this->orderId = $orderId;
        $this->status = ReservationStatus::Active;
        $this->lines = $lineEntities;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @param list<NewReservationLine> $lines */
    public static function forOrder(string $orderId, array $lines, \DateTimeImmutable $now): self
    {
        return new self($orderId, $lines, $now);
    }

    /** @throws ReservationAlreadyFinalized when not active (R2) */
    public function release(\DateTimeImmutable $now): void
    {
        $this->assertActive('release');
        $this->status = ReservationStatus::Released;
        $this->updatedAt = $now;
    }

    /** @throws ReservationAlreadyFinalized when not active (R2) */
    public function commit(\DateTimeImmutable $now): void
    {
        $this->assertActive('commit');
        $this->status = ReservationStatus::Committed;
        $this->updatedAt = $now;
    }

    public function id(): ReservationId
    {
        return $this->id;
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function status(): ReservationStatus
    {
        return $this->status;
    }

    /** @return list<ReservationLine> */
    public function lines(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function assertActive(string $action): void
    {
        if ($this->status !== ReservationStatus::Active) {
            throw ReservationAlreadyFinalized::cannot($action, $this->status);
        }
    }
}
