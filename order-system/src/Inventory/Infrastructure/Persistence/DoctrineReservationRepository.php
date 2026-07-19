<?php

declare(strict_types=1);

namespace App\Inventory\Infrastructure\Persistence;

use App\Inventory\Domain\Reservation;
use App\Inventory\Domain\ReservationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Doctrine adapter for the ReservationRepository port. R1 ("exactly one
 * reservation per order") is the UNIQUE index on order_id — with checkout
 * dispatching OrderPlaced exactly once per new order inside one transaction,
 * a violation here would be a bug, so it is allowed to explode rather than
 * being translated into a domain exception.
 */
final class DoctrineReservationRepository implements ReservationRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function byOrderIdOrNull(string $orderId): ?Reservation
    {
        return $this->em->getRepository(Reservation::class)->findOneBy(['orderId' => $orderId]);
    }

    public function add(Reservation $reservation): void
    {
        $this->em->persist($reservation);
        $this->em->flush();
    }

    public function save(Reservation $reservation): void
    {
        $this->em->flush();
    }
}
