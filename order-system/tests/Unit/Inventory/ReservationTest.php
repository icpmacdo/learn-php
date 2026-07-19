<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inventory;

use App\Inventory\Domain\NewReservationLine;
use App\Inventory\Domain\Reservation;
use App\Inventory\Domain\ReservationAlreadyFinalized;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Shared\Domain\UuidV7;
use Codeception\Test\Unit;

/**
 * R2 — the once-only guard. Every finalize-after-finalize combination
 * throws: this status check is the seed of part 4's idempotency lesson.
 */
final class ReservationTest extends Unit
{
    public function testForOrderStartsActiveWithLinesAndMintedIdentity(): void
    {
        $reservation = $this->reservation();

        $this->assertSame(ReservationStatus::Active, $reservation->status());
        $this->assertSame('order-uuid-1', $reservation->orderId());
        $this->assertTrue(UuidV7::isWellFormed($reservation->id()->value));
        $this->assertCount(2, $reservation->lines());
        $this->assertSame('WIDGET-1', $reservation->lines()[0]->sku()->value);
        $this->assertSame(2, $reservation->lines()[0]->quantity()->value);
    }

    public function testAReservationNeedsLines(): void
    {
        $this->expectException(\DomainException::class);
        Reservation::forOrder('order-uuid-1', [], $this->now());
    }

    public function testReleaseFinalizesOnce(): void
    {
        $reservation = $this->reservation();
        $reservation->release($this->now());

        $this->assertSame(ReservationStatus::Released, $reservation->status());
    }

    public function testCommitFinalizesOnce(): void
    {
        $reservation = $this->reservation();
        $reservation->commit($this->now());

        $this->assertSame(ReservationStatus::Committed, $reservation->status());
    }

    /** @dataProvider doubleFinalizations */
    public function testEveryDoubleFinalizationThrows(string $first, string $second): void
    {
        $reservation = $this->reservation();
        $first === 'release' ? $reservation->release($this->now()) : $reservation->commit($this->now());

        $this->expectException(ReservationAlreadyFinalized::class); // R2
        $second === 'release' ? $reservation->release($this->now()) : $reservation->commit($this->now());
    }

    /** @return iterable<string, array{string, string}> */
    public static function doubleFinalizations(): iterable
    {
        yield 'release then release' => ['release', 'release'];
        yield 'release then commit' => ['release', 'commit'];
        yield 'commit then commit' => ['commit', 'commit'];
        yield 'commit then release' => ['commit', 'release'];
    }

    private function reservation(): Reservation
    {
        return Reservation::forOrder('order-uuid-1', [
            new NewReservationLine(Sku::fromString('WIDGET-1'), Quantity::of(2)),
            new NewReservationLine(Sku::fromString('GADGET-1'), Quantity::of(3)),
        ], $this->now());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-18T12:00:00+00:00');
    }
}
