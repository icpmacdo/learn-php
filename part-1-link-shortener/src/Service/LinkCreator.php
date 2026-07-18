<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Link;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;

/**
 * Creates a Link with a freshly generated short code, retrying on collision.
 *
 * The pattern: generate -> INSERT -> catch the unique-index violation ->
 * regenerate. The database's unique index (uniq_link_code) is the source of
 * truth. A pre-check SELECT would be a bug: two concurrent requests could
 * both see "code is free" and both insert (check-then-act race). The index
 * cannot be raced.
 */
class LinkCreator
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly CodeGenerator $codeGenerator,
        private readonly ManagerRegistry $registry,
    ) {
    }

    public function create(string $url): Link
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $link = new Link($this->codeGenerator->generate(), $url);

            /** @var ObjectManager $em */
            $em = $this->registry->getManager();

            try {
                $em->persist($link);
                $em->flush();

                return $link;
            } catch (UniqueConstraintViolationException) {
                // A failed flush closes the EntityManager for good;
                // reset it so the retry gets a working one.
                $this->registry->resetManager();
            }
        }

        throw new CodeCollisionException(
            sprintf('Could not generate a unique short code after %d attempts.', self::MAX_ATTEMPTS)
        );
    }
}
