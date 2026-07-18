<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TeamRole;
use App\Repository\TeamMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * The user<->team join with a payload (the role) -- the heart of
 * authorization: every voter decision starts from "what is this user's
 * membership in this team, if any?".
 */
#[ORM\Entity(repositoryClass: TeamMembershipRepository::class)]
#[ORM\Table(name: 'team_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership', columns: ['user_id', 'team_id'])]
#[ORM\Index(name: 'idx_membership_team', columns: ['team_id'])]
class TeamMembership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Team::class)]
    #[ORM\JoinColumn(name: 'team_id', nullable: false, onDelete: 'CASCADE')]
    private Team $team;

    #[ORM\Column(length: 10, enumType: TeamRole::class)]
    private TeamRole $role;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, Team $team, TeamRole $role)
    {
        $this->user = $user;
        $this->team = $team;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTeam(): Team
    {
        return $this->team;
    }

    public function getRole(): TeamRole
    {
        return $this->role;
    }

    public function setRole(TeamRole $role): void
    {
        $this->role = $role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
