<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An account. The unique index on `email` is the source of truth for
 * uniqueness -- registration relies on it to detect duplicates (no racy
 * pre-check SELECT; same lesson as part 1's code collision).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(name: 'password_hash', length: 255)]
    private string $passwordHash = '';

    #[ORM\Column(name: 'display_name', length: 100)]
    private string $displayName;

    /** Stored as DATETIME; always UTC (set here, PHP's timezone is UTC too). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $email, string $displayName)
    {
        $this->email = $email;
        $this->displayName = $displayName;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** The hash is produced by Symfony's password hasher, which needs the user object first -- hence the setter. */
    public function setPasswordHash(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    // --- UserInterface / PasswordAuthenticatedUserInterface ---

    public function getUserIdentifier(): string
    {
        \assert($this->email !== ''); // NotBlank-validated at registration

        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    /**
     * Global roles are flat: everyone is ROLE_USER. Team-level member/admin
     * lives in TeamMembership and is enforced by voters, NOT by roles --
     * roles are global to the account, membership is per team.
     *
     * @return string[]
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    /**
     * Required by UserInterface until Symfony 8; nothing to erase because no
     * plaintext credential is ever stored on this object. The attribute tells
     * Symfony this implementation is intentionally empty (avoids a 7.3+
     * deprecation warning).
     */
    #[\Deprecated]
    public function eraseCredentials(): void
    {
    }
}
