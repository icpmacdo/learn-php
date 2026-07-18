<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Task;
use App\Entity\User;
use App\Security\TeamMembershipResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Task-level permissions, from the §4 matrix:
 *
 *   TASK_VIEW     any member of the task's team (denial -> 404 in controllers)
 *   TASK_EDIT     any member (shared ownership of the board)
 *   TASK_COMMENT  any member
 *   TASK_DELETE   the task's creator, or a team admin (moderation)
 *
 * @extends Voter<string, Task>
 */
final class TaskVoter extends Voter
{
    public const VIEW = 'TASK_VIEW';
    public const EDIT = 'TASK_EDIT';
    public const DELETE = 'TASK_DELETE';
    public const COMMENT = 'TASK_COMMENT';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE, self::COMMENT];

    public function __construct(private readonly TeamMembershipResolver $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Task;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Task $task */
        $task = $subject;
        $team = $task->getTeam();

        return match ($attribute) {
            self::VIEW, self::EDIT, self::COMMENT => $this->memberships->isMember($user, $team),
            // Identity compared by email (the unique identifier), not object
            // identity -- voter unit tests build entities in memory where two
            // distinct users both have a null id.
            self::DELETE => $this->memberships->isAdmin($user, $team)
                || ($this->memberships->isMember($user, $team)
                    && $task->getCreatedBy()->getUserIdentifier() === $user->getUserIdentifier()),
            default => false,
        };
    }
}
