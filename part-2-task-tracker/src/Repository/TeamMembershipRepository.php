<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Enum\TeamRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TeamMembership>
 */
class TeamMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TeamMembership::class);
    }

    public function findOneByUserAndTeam(User $user, Team $team): ?TeamMembership
    {
        // Hits the uniq_membership (user_id, team_id) index.
        return $this->findOneBy(['user' => $user, 'team' => $team]);
    }

    /**
     * @return TeamMembership[]
     */
    public function findByTeamWithUsers(Team $team): array
    {
        // Fetch-join the user so listing members is one query, not 1+N.
        return $this->createQueryBuilder('m')
            ->addSelect('u')
            ->join('m.user', 'u')
            ->andWhere('m.team = :team')
            ->setParameter('team', $team)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TeamMembership[]
     */
    public function findByUserWithTeams(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->addSelect('t')
            ->join('m.team', 't')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.createdAt', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countAdmins(Team $team): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.team = :team')
            ->andWhere('m.role = :role')
            ->setParameter('team', $team)
            ->setParameter('role', TeamRole::Admin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(TeamMembership $membership): void
    {
        $em = $this->getEntityManager();
        $em->persist($membership);
        $em->flush();
    }

    public function remove(TeamMembership $membership): void
    {
        $em = $this->getEntityManager();
        $em->remove($membership);
        $em->flush();
    }
}
