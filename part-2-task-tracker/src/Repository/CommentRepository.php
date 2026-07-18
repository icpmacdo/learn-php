<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Task;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Comment>
 */
class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /**
     * @return Comment[]
     */
    public function findByTaskOldestFirst(Task $task): array
    {
        // Fetch-join the author: one query for the whole thread.
        return $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.author', 'a')
            ->andWhere('c.task = :task')
            ->setParameter('task', $task)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All comments for a set of tasks in one query (the dashboard renders
     * every task's thread; per-task queries would be 1+N), grouped by task id.
     *
     * @param Task[] $tasks
     *
     * @return array<int, Comment[]> task id => comments, oldest first
     */
    public function findByTasksGroupedOldestFirst(array $tasks): array
    {
        if ($tasks === []) {
            return [];
        }

        /** @var Comment[] $comments */
        $comments = $this->createQueryBuilder('c')
            ->addSelect('a')
            ->join('c.author', 'a')
            ->andWhere('c.task IN (:tasks)')
            ->setParameter('tasks', $tasks)
            ->orderBy('c.createdAt', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($comments as $comment) {
            $grouped[(int) $comment->getTask()->getId()][] = $comment;
        }

        return $grouped;
    }

    public function save(Comment $comment): void
    {
        $em = $this->getEntityManager();
        $em->persist($comment);
        $em->flush();
    }

    public function remove(Comment $comment): void
    {
        $em = $this->getEntityManager();
        $em->remove($comment);
        $em->flush();
    }
}
