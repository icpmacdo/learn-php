<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Link;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * All database access for Link lives here, written explicitly so the SQL
 * Doctrine generates is easy to inspect (see README "Peeking at the raw SQL").
 *
 * Every method binds user input as a parameter (:code) -- Doctrine sends the
 * query and the values separately (prepared statements), which is why SQL
 * injection is a non-issue here.
 *
 * @extends ServiceEntityRepository<Link>
 */
class LinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Link::class);
    }

    public function findOneByCode(string $code): ?Link
    {
        // SELECT ... FROM link WHERE code = ? -- hits the uniq_link_code index.
        return $this->createQueryBuilder('l')
            ->andWhere('l.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Link[]
     */
    public function findAllNewestFirst(): array
    {
        // id DESC as a tiebreaker: created_at is DATETIME (second precision),
        // so links created in the same second would otherwise order randomly.
        return $this->createQueryBuilder('l')
            ->orderBy('l.createdAt', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomic increment: a single UPDATE link SET hits = hits + 1 WHERE code = ?
     * executed by the database. Reading the entity, adding 1 in PHP and
     * flushing would be a lost update waiting to happen under concurrency
     * (two requests read hits=4, both write hits=5).
     */
    public function incrementHits(string $code): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE App\Entity\Link l SET l.hits = l.hits + 1 WHERE l.code = :code'
        )
            ->setParameter('code', $code)
            ->execute();
    }

    public function remove(Link $link): void
    {
        $em = $this->getEntityManager();
        $em->remove($link);
        $em->flush();
    }
}
