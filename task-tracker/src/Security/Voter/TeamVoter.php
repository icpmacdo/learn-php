<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Team;
use App\Entity\User;
use App\Security\TeamMembershipResolver;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Team-level permissions, from the §4 matrix:
 *
 *   TEAM_VIEW           any member          (denial is mapped to 404 by controllers)
 *   TEAM_CREATE_TASK    any member
 *   TEAM_EDIT           admin only (rename)
 *   TEAM_DELETE         admin only
 *   TEAM_MANAGE_MEMBERS admin only (self-removal = "leave" is allowed in the
 *                       controller BEFORE this check -- leaving is not managing)
 *
 * @extends Voter<string, Team>
 */
final class TeamVoter extends Voter
{
    public const VIEW = 'TEAM_VIEW';
    public const EDIT = 'TEAM_EDIT';
    public const DELETE = 'TEAM_DELETE';
    public const MANAGE_MEMBERS = 'TEAM_MANAGE_MEMBERS';
    public const CREATE_TASK = 'TEAM_CREATE_TASK';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE, self::MANAGE_MEMBERS, self::CREATE_TASK];

    public function __construct(private readonly TeamMembershipResolver $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, self::ATTRIBUTES, true) && $subject instanceof Team;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Team $team */
        $team = $subject;

        return match ($attribute) {
            self::VIEW, self::CREATE_TASK => $this->memberships->isMember($user, $team),
            self::EDIT, self::DELETE, self::MANAGE_MEMBERS => $this->memberships->isAdmin($user, $team),
            default => false,
        };
    }
}
