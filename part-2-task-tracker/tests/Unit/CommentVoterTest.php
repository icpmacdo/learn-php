<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Comment;
use App\Entity\Task;
use App\Entity\Team;
use App\Enum\TeamRole;
use App\Security\Voter\CommentVoter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class CommentVoterTest extends VoterTestCase
{
    public function testEditIsAuthorOnlyAdminsIncludedInTheBan(): void
    {
        [$voter, $tokens, $comment] = $this->scenario();

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($tokens['author'], $comment, [CommentVoter::EDIT]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($tokens['member'], $comment, [CommentVoter::EDIT]));
        $this->assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($tokens['admin'], $comment, [CommentVoter::EDIT]),
            'admins moderate by delete, never by rewriting words',
        );
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($tokens['outsider'], $comment, [CommentVoter::EDIT]));
    }

    public function testDeleteIsAuthorOrAdmin(): void
    {
        [$voter, $tokens, $comment] = $this->scenario();

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($tokens['author'], $comment, [CommentVoter::DELETE]));
        $this->assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($tokens['admin'], $comment, [CommentVoter::DELETE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($tokens['member'], $comment, [CommentVoter::DELETE]));
        $this->assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($tokens['outsider'], $comment, [CommentVoter::DELETE]));
    }

    /**
     * @return array{CommentVoter, array<string, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface>, Comment}
     */
    private function scenario(): array
    {
        $team = new Team('Comments');
        $author = $this->user('author@example.com');
        $member = $this->user('member@example.com');
        $admin = $this->user('admin@example.com');
        $outsider = $this->user('outsider@example.com');

        $task = new Task($team, 'Discussed', $admin);
        $comment = new Comment($task, $author, 'my words');

        $voter = new CommentVoter($this->resolver([
            [$author, $team, TeamRole::Member],
            [$member, $team, TeamRole::Member],
            [$admin, $team, TeamRole::Admin],
        ]));

        $tokens = [
            'author' => $this->tokenFor($author),
            'member' => $this->tokenFor($member),
            'admin' => $this->tokenFor($admin),
            'outsider' => $this->tokenFor($outsider),
        ];

        return [$voter, $tokens, $comment];
    }
}
