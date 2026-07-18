<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Team;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Repository\TeamMembershipRepository;

/**
 * The one place that answers "what is this user's role in this team, if any?".
 *
 * All three voters share it, so the membership lookup exists exactly once --
 * and it memoizes per request: a single API call can trigger several voter
 * checks (view gate + action check), which must not mean several identical
 * SELECTs.
 */
class TeamMembershipResolver
{
    /** @var array<string, TeamRole|null> */
    private array $memo = [];

    public function __construct(private readonly TeamMembershipRepository $memberships)
    {
    }

    public function roleFor(User $user, Team $team): ?TeamRole
    {
        $key = $user->getUserIdentifier().'|'.$team->getId();

        if (!\array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $this->memberships->findOneByUserAndTeam($user, $team)?->getRole();
        }

        return $this->memo[$key];
    }

    public function isMember(User $user, Team $team): bool
    {
        return $this->roleFor($user, $team) !== null;
    }

    public function isAdmin(User $user, Team $team): bool
    {
        return $this->roleFor($user, $team) === TeamRole::Admin;
    }

    /**
     * Membership changes must drop the memo, or a voter check later in the
     * same request would see the pre-change role.
     */
    public function forget(User $user, Team $team): void
    {
        unset($this->memo[$user->getUserIdentifier().'|'.$team->getId()]);
    }
}
