<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Team>
 */
class TeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Team::class);
    }

    public function save(Team $team): void
    {
        $em = $this->getEntityManager();
        $em->persist($team);
        $em->flush();
    }

    public function remove(Team $team): void
    {
        // Memberships, tasks and (transitively) comments go with it --
        // ON DELETE CASCADE at the database level, one DELETE statement here.
        $em = $this->getEntityManager();
        $em->remove($team);
        $em->flush();
    }
}
