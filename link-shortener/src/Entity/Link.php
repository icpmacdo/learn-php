<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\LinkRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A shortened link: a 7-char base62 code pointing at a target URL.
 *
 * The unique index on `code` is the source of truth for uniqueness --
 * LinkCreator relies on it to detect collisions (no racy pre-check SELECT).
 */
#[ORM\Entity(repositoryClass: LinkRepository::class)]
#[ORM\Table(name: 'link')]
#[ORM\UniqueConstraint(name: 'uniq_link_code', columns: ['code'])]
class Link
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT, options: ['unsigned' => true])]
    private ?int $id = null;

    /**
     * ascii_bin collation: case-sensitive (base62 needs 'a' != 'A')
     * and keeps the unique index small.
     */
    #[ORM\Column(length: 7, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $code;

    #[ORM\Column(length: 2048)]
    private string $url;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $hits = 0;

    /** Stored as DATETIME; always UTC (set here, PHP's timezone is UTC too). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, string $url)
    {
        $this->code = $code;
        $this->url = $url;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getHits(): int
    {
        return $this->hits;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
