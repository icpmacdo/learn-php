<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use App\Security\TeamMembershipResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Comment-level permissions, from the §4 matrix:
 *
 *   COMMENT_EDIT    the author only -- even admins don't edit others' words;
 *                   moderation is deletion, never alteration
 *   COMMENT_DELETE  the author, or a team admin (moderation)
 *
 * (Viewing a comment is TASK_VIEW on its task; there is no COMMENT_VIEW.)
 *
 * @extends Voter<string, Comment>
 */
final class CommentVoter extends Voter
{
    public const EDIT = 'COMMENT_EDIT';
    public const DELETE = 'COMMENT_DELETE';

    private const ATTRIBUTES = [self::EDIT, self::DELETE];

    public function __construct(private readonly TeamMembershipResolver $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Comment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Comment $comment */
        $comment = $subject;
        $team = $comment->getTask()->getTeam();

        if (!$this->memberships->isMember($user, $team)) {
            // Not even a member: no comment permission of any kind. (The
            // controller's TASK_VIEW gate already turned this into a 404.)
            return false;
        }

        $isAuthor = $comment->getAuthor()->getUserIdentifier() === $user->getUserIdentifier();

        return match ($attribute) {
            self::EDIT => $isAuthor,
            self::DELETE => $isAuthor || $this->memberships->isAdmin($user, $team),
            default => false,
        };
    }
}
