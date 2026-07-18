<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function findOneByHash(string $tokenHash): ?ApiToken
    {
        // Hits the uniq_api_token_hash index -- the deterministic-hash lookup
        // that makes SHA-256 (not bcrypt) the right choice for API tokens.
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Scoped by owner: querying "this id AND this user" is what makes someone
     * else's token id a plain 404 -- the row is simply never found for you.
     */
    public function findOneByIdAndUser(int $id, User $user): ?ApiToken
    {
        return $this->findOneBy(['id' => $id, 'user' => $user]);
    }

    /**
     * @return ApiToken[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'ASC', 'id' => 'ASC']);
    }

    public function save(ApiToken $token): void
    {
        $em = $this->getEntityManager();
        $em->persist($token);
        $em->flush();
    }

    public function remove(ApiToken $token): void
    {
        $em = $this->getEntityManager();
        $em->remove($token);
        $em->flush();
    }
}
