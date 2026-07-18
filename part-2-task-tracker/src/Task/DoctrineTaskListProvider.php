<?php

declare(strict_types=1);

namespace App\Task;

use App\Entity\Team;
use App\Http\ApiResponseMapper;
use App\Repository\TaskRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;

/**
 * The MySQL answer to "a page of this team's tasks": builds the Doctrine
 * query from an already-validated TaskListQuery, paginates, and maps rows to
 * the response array. Knows nothing about HTTP or caching.
 */
final class DoctrineTaskListProvider implements TaskListProviderInterface
{
    /**
     * The sort whitelist mapped to explicit DQL expressions -- ORDER BY is
     * never interpolated from user input (SQL-injection-shaped thinking even
     * though Doctrine parameterizes values). `priority` sorts by rank
     * (high > medium > low), not alphabetically, via the CASE below.
     */
    private const SORT_DQL = [
        'createdAt' => 't.createdAt',
        'dueDate' => 't.dueDate',
        'status' => 't.status',
        'priority' => 'priorityRank',
    ];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly ApiResponseMapper $mapper,
    ) {
    }

    public function list(Team $team, TaskListQuery $query): array
    {
        $qb = $this->tasks->createQueryBuilder('t')
            ->addSelect('assignee', 'createdBy')
            ->leftJoin('t.assignee', 'assignee')
            ->join('t.createdBy', 'createdBy')
            ->addSelect("CASE t.priority WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END AS HIDDEN priorityRank")
            ->andWhere('t.team = :team')
            ->setParameter('team', $team);

        if ($query->status !== null) {
            $qb->andWhere('t.status = :status')->setParameter('status', $query->status);
        }
        if ($query->unassignedOnly) {
            $qb->andWhere('t.assignee IS NULL');
        } elseif ($query->assigneeId !== null) {
            $qb->andWhere('IDENTITY(t.assignee) = :assigneeId')->setParameter('assigneeId', $query->assigneeId);
        }

        $qb->orderBy(self::SORT_DQL[$query->sort], $query->direction)
            ->addOrderBy('t.id', $query->direction) // deterministic tiebreak (DATETIME has second precision)
            ->setFirstResult(($query->page - 1) * $query->limit)
            ->setMaxResults($query->limit);

        // Offset pagination via Doctrine's Paginator (count + page queries).
        // fetchJoinCollection false: only to-one joins, no row duplication.
        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        $total = \count($paginator);

        return [
            'tasks' => array_map($this->mapper->task(...), iterator_to_array($paginator->getIterator(), preserve_keys: false)),
            'page' => $query->page,
            'limit' => $query->limit,
            'total' => $total,
            'pages' => (int) ceil($total / $query->limit),
        ];
    }
}
