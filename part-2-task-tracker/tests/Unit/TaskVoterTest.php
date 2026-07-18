<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Task;
use App\Entity\Team;
use App\Enum\TeamRole;
use App\Security\Voter\TaskVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class TaskVoterTest extends VoterTestCase
{
    public function testMembersViewEditAndCommentOutsidersDoNot(): void
    {
        $team = new Team('Tasks');
        $member = $this->user('member@example.com');
        $outsider = $this->user('outsider@example.com');
        $task = new Task($team, 'Subject', $member);

        $voter = new TaskVoter($this->resolver([[$member, $team, TeamRole::Member]]));

        foreach ([TaskVoter::VIEW, TaskVoter::EDIT, TaskVoter::COMMENT] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_GRANTED,
                $voter->vote($this->tokenFor($member), $task, [$attribute]),
                "member should get {$attribute}",
            );
            $this->assertSame(
                VoterInterface::ACCESS_DENIED,
                $voter->vote($this->tokenFor($outsider), $task, [$attribute]),
                "outsider should be denied {$attribute}",
            );
        }
    }

    public function testDeleteIsCreatorOrAdmin(): void
    {
        $team = new Team('Tasks');
        $creator = $this->user('creator@example.com');
        $otherMember = $this->user('other@example.com');
        $admin = $this->user('admin@example.com');
        $task = new Task($team, 'Deletable', $creator);

        $voter = new TaskVoter($this->resolver([
            [$creator, $team, TeamRole::Member],
            [$otherMember, $team, TeamRole::Member],
            [$admin, $team, TeamRole::Admin],
        ]));

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($creator), $task, [TaskVoter::DELETE]), 'creator deletes own task');
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->tokenFor($admin), $task, [TaskVoter::DELETE]), 'admin moderates by delete');
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->tokenFor($otherMember), $task, [TaskVoter::DELETE]), 'plain member cannot delete another\'s task');
    }

    public function testACreatorWhoLeftTheTeamCannotTouchTheirOldTask(): void
    {
        $team = new Team('Tasks');
        $formerMember = $this->user('gone@example.com');
        $task = new Task($team, 'Orphaned authority', $formerMember);

        // No memberships at all: the creator has left.
        $voter = new TaskVoter($this->resolver([]));

        foreach ([TaskVoter::VIEW, TaskVoter::EDIT, TaskVoter::DELETE, TaskVoter::COMMENT] as $attribute) {
            $this->assertSame(
                VoterInterface::ACCESS_DENIED,
                $voter->vote($this->tokenFor($formerMember), $task, [$attribute]),
                "ex-member (even as creator) should be denied {$attribute}",
            );
        }
    }
}
