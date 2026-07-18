<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamMembershipRepository;
use App\Security\TeamMembershipResolver;
use Codeception\Test\Unit;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Shared plumbing for voter tests: in-memory entities, a resolver backed by a
 * stubbed repository (no database -- authorization is pure logic), and a
 * security token stub.
 *
 * NOTE in-memory entities have null ids, which is exactly why the voters
 * compare users by getUserIdentifier() (unique email), never by id or object
 * identity.
 */
abstract class VoterTestCase extends Unit
{
    protected function user(string $email): User
    {
        return new User($email, ucfirst(explode('@', $email)[0]));
    }

    /**
     * @param array<array{User, Team, TeamRole}> $memberships
     */
    protected function resolver(array $memberships): TeamMembershipResolver
    {
        $rows = array_map(
            static fn (array $m): TeamMembership => new TeamMembership($m[0], $m[1], $m[2]),
            $memberships,
        );

        $repository = $this->createMock(TeamMembershipRepository::class);
        $repository->method('findOneByUserAndTeam')->willReturnCallback(
            static function (User $user, Team $team) use ($rows): ?TeamMembership {
                foreach ($rows as $row) {
                    if ($row->getUser() === $user && $row->getTeam() === $team) {
                        return $row;
                    }
                }

                return null;
            },
        );

        return new TeamMembershipResolver($repository);
    }

    protected function tokenFor(?User $user): TokenInterface
    {
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
