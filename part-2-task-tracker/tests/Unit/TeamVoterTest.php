<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Team;
use App\Enum\TeamRole;
use App\Security\Voter\TeamVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * The team rows of the §4 matrix as a pure-logic grid:
 * attribute x (outsider | member | admin).
 */
final class TeamVoterTest extends VoterTestCase
{
    public function testTheFullAttributeRoleGrid(): void
    {
        $team = new Team('Voted');
        $admin = $this->user('admin@example.com');
        $member = $this->user('member@example.com');
        $outsider = $this->user('outsider@example.com');

        $voter = new TeamVoter($this->resolver([
            [$admin, $team, TeamRole::Admin],
            [$member, $team, TeamRole::Member],
        ]));

        // attribute => [admin granted, member granted, outsider granted]
        $grid = [
            TeamVoter::VIEW => [true, true, false],
            TeamVoter::CREATE_TASK => [true, true, false],
            TeamVoter::EDIT => [true, false, false],
            TeamVoter::DELETE => [true, false, false],
            TeamVoter::MANAGE_MEMBERS => [true, false, false],
        ];

        foreach ($grid as $attribute => [$adminGranted, $memberGranted, $outsiderGranted]) {
            $this->assertVote($voter, $admin, $team, $attribute, $adminGranted, 'admin');
            $this->assertVote($voter, $member, $team, $attribute, $memberGranted, 'member');
            $this->assertVote($voter, $outsider, $team, $attribute, $outsiderGranted, 'outsider');
        }
    }

    public function testDeniesWhenNoUserIsAuthenticated(): void
    {
        $team = new Team('Voted');
        $voter = new TeamVoter($this->resolver([]));

        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor(null), $team, [TeamVoter::VIEW]),
        );
    }

    public function testAbstainsOnForeignAttributesAndSubjects(): void
    {
        $team = new Team('Voted');
        $user = $this->user('user@example.com');
        $voter = new TeamVoter($this->resolver([[$user, $team, TeamRole::Admin]]));

        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->tokenFor($user), $team, ['TASK_VIEW']),
            'task attributes are not this voter\'s business',
        );
        $this->assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->tokenFor($user), new \stdClass(), [TeamVoter::VIEW]),
        );
    }

    private function assertVote(
        TeamVoter $voter,
        \App\Entity\User $user,
        Team $team,
        string $attribute,
        bool $granted,
        string $who,
    ): void {
        $this->assertSame(
            $granted ? VoterInterface::ACCESS_GRANTED : VoterInterface::ACCESS_DENIED,
            $voter->vote($this->tokenFor($user), $team, [$attribute]),
            sprintf('%s / %s should be %s', $attribute, $who, $granted ? 'granted' : 'denied'),
        );
    }
}
