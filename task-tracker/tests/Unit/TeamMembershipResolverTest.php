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

final class TeamMembershipResolverTest extends Unit
{
    public function testMemoizesTheLookupWithinARequest(): void
    {
        $user = new User('memo@example.com', 'Memo');
        $team = new Team('Memoized');
        $membership = new TeamMembership($user, $team, TeamRole::Admin);

        $repository = $this->createMock(TeamMembershipRepository::class);
        // One API call triggers several voter checks; the SELECT must run once.
        $repository->expects($this->once())
            ->method('findOneByUserAndTeam')
            ->with($user, $team)
            ->willReturn($membership);

        $resolver = new TeamMembershipResolver($repository);

        $this->assertSame(TeamRole::Admin, $resolver->roleFor($user, $team));
        $this->assertTrue($resolver->isMember($user, $team));
        $this->assertTrue($resolver->isAdmin($user, $team));
    }

    public function testMemoizesNegativeResultsToo(): void
    {
        $user = new User('nobody@example.com', 'Nobody');
        $team = new Team('Exclusive');

        $repository = $this->createMock(TeamMembershipRepository::class);
        $repository->expects($this->once())
            ->method('findOneByUserAndTeam')
            ->willReturn(null);

        $resolver = new TeamMembershipResolver($repository);

        $this->assertNull($resolver->roleFor($user, $team));
        $this->assertFalse($resolver->isMember($user, $team));
        $this->assertFalse($resolver->isAdmin($user, $team));
    }

    public function testForgetDropsTheMemoSoRoleChangesAreVisible(): void
    {
        $user = new User('promoted@example.com', 'Promoted');
        $team = new Team('Changing');
        $before = new TeamMembership($user, $team, TeamRole::Member);
        $after = new TeamMembership($user, $team, TeamRole::Admin);

        $repository = $this->createMock(TeamMembershipRepository::class);
        $repository->expects($this->exactly(2))
            ->method('findOneByUserAndTeam')
            ->willReturnOnConsecutiveCalls($before, $after);

        $resolver = new TeamMembershipResolver($repository);

        $this->assertSame(TeamRole::Member, $resolver->roleFor($user, $team));
        $resolver->forget($user, $team);
        $this->assertSame(TeamRole::Admin, $resolver->roleFor($user, $team));
    }
}
