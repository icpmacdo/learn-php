<?php

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Inventory\Domain\NewReservationLine;
use App\Inventory\Domain\Reservation;
use App\Inventory\Domain\ReservationRepository;
use App\Inventory\Domain\ReservationStatus;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Reservation adapter round-trips, and R1 ("exactly one reservation per
 * order") really is enforced by uniq_inventory_reservation_order — the test
 * drives the index in real MySQL, the same proof DoctrineProductRepositoryCest
 * gives P2's unique SKU. Without this, dropping the index from a future
 * migration would leave every suite green while the claimed enforcement of
 * R1 silently vanished.
 */
final class DoctrineReservationRepositoryCest
{
    public function roundTripsAReservationWithItsLines(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $orderId = $this->orderId();
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');

        $repo->add(Reservation::forOrder($orderId, [
            new NewReservationLine(Sku::fromString('IT-RES-A'), Quantity::of(2)),
            new NewReservationLine(Sku::fromString('IT-RES-B'), Quantity::of(5)),
        ], $now));
        $this->em($I)->clear();

        $reloaded = $repo->byOrderIdOrNull($orderId);
        $I->assertNotNull($reloaded);
        $I->assertSame($orderId, $reloaded->orderId());
        $I->assertSame(ReservationStatus::Active, $reloaded->status());
        $quantitiesBySku = [];
        foreach ($reloaded->lines() as $line) {
            $quantitiesBySku[$line->sku()->value] = $line->quantity()->value;
        }
        $I->assertSame(['IT-RES-A' => 2, 'IT-RES-B' => 5], $quantitiesBySku);
    }

    public function unknownOrderIdIsNull(IntegrationTester $I): void
    {
        $I->assertNull($this->repo($I)->byOrderIdOrNull($this->orderId()));
    }

    public function aSecondReservationForTheSameOrderTripsTheR1UniqueIndex(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $orderId = $this->orderId();
        $now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');
        $line = static fn (): array => [new NewReservationLine(Sku::fromString('IT-RES-DUP'), Quantity::of(1))];

        $repo->add(Reservation::forOrder($orderId, $line(), $now));

        // No domain translation on purpose (see the adapter's docblock):
        // checkout dispatches OrderPlaced exactly once per order, so a
        // duplicate here is a bug and is allowed to explode as the driver
        // exception. The point of this test is that it explodes AT ALL.
        $I->expectThrowable(UniqueConstraintViolationException::class, static function () use ($repo, $orderId, $line, $now): void {
            $repo->add(Reservation::forOrder($orderId, $line(), $now));
        });
    }

    private function repo(IntegrationTester $I): ReservationRepository
    {
        return $I->grabService(ReservationRepository::class);
    }

    private function em(IntegrationTester $I): EntityManagerInterface
    {
        return $I->grabService(EntityManagerInterface::class);
    }

    private function orderId(): string
    {
        return 'it-res-'.bin2hex(random_bytes(8));
    }
}
