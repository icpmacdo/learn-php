<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Task;
use App\Entity\Team;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Task>
 */
class TaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Task::class);
    }

    /**
     * Task counts per status for one team (the dashboard summary source;
     * cached by TeamSummaryProvider). One GROUP BY on idx_task_team_status.
     *
     * @return array<string, int> status value => count (statuses with no tasks are absent)
     */
    public function countByStatus(Team $team): array
    {
        /** @var list<array{status: \App\Enum\TaskStatus, n: int|string}> $rows */
        $rows = $this->createQueryBuilder('t')
            ->select('t.status AS status', 'COUNT(t.id) AS n')
            ->andWhere('t.team = :team')
            ->setParameter('team', $team)
            ->groupBy('t.status')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The dashboard's task list: default API sort (createdAt desc), joined
     * people, NOT paginated (a team dashboard shows the whole board) and NOT
     * cached (it renders comments, which are excluded from cached payloads).
     *
     * @return Task[]
     */
    public function findByTeamNewestFirst(Team $team): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('assignee', 'createdBy')
            ->leftJoin('t.assignee', 'assignee')
            ->join('t.createdBy', 'createdBy')
            ->andWhere('t.team = :team')
            ->setParameter('team', $team)
            ->orderBy('t.createdAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function save(Task $task): void
    {
        $em = $this->getEntityManager();
        $em->persist($task);
        $em->flush();
    }

    public function remove(Task $task): void
    {
        // Comments cascade at the database level.
        $em = $this->getEntityManager();
        $em->remove($task);
        $em->flush();
    }
}
