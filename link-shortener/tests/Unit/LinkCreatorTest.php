<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Link;
use App\Service\CodeCollisionException;
use App\Service\CodeGenerator;
use App\Service\LinkCreator;
use Codeception\Test\Unit;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The collision-retry logic, tested without a database: the EntityManager is
 * mocked to throw the same UniqueConstraintViolationException the real MySQL
 * unique index would produce.
 */
final class LinkCreatorTest extends Unit
{
    public function testRetriesOnCollisionAndEventuallySucceeds(): void
    {
        // First two flushes collide, third succeeds.
        $flushes = 0;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(3))->method('persist');
        $em->expects($this->exactly(3))->method('flush')
            ->willReturnCallback(function () use (&$flushes): void {
                if (++$flushes <= 2) {
                    throw $this->uniqueViolation();
                }
            });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);
        // A failed flush closes the EM; the creator must reset it each time.
        $registry->expects($this->exactly(2))->method('resetManager');

        $creator = new LinkCreator(new CodeGenerator(), $registry);
        $link = $creator->create('https://example.com/page');

        $this->assertInstanceOf(Link::class, $link);
        $this->assertSame('https://example.com/page', $link->getUrl());
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{7}$/', $link->getCode());
    }

    public function testGivesUpAfterFiveAttempts(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(LinkCreator::MAX_ATTEMPTS))->method('flush')
            ->willReturnCallback(function (): void {
                throw $this->uniqueViolation();
            });

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $creator = new LinkCreator(new CodeGenerator(), $registry);

        $this->expectException(CodeCollisionException::class);
        $creator->create('https://example.com/page');
    }

    private function uniqueViolation(): UniqueConstraintViolationException
    {
        return new UniqueConstraintViolationException(
            PdoDriverException::new(new \PDOException("Duplicate entry 'abc1234' for key 'uniq_link_code'")),
            null,
        );
    }
}
