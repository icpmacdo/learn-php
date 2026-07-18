<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\Link;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Maps a Link entity to its JSON representation, by hand -- no serializer
 * magic, so the exact response shape is visible in one place.
 *
 * shortUrl is derived from the current request's scheme/host via the router
 * (UrlGeneratorInterface), never stored in the database.
 */
final class LinkResponseMapper
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * @return array{code: string, url: string, shortUrl: string, hits: int, createdAt: string}
     */
    public function toArray(Link $link): array
    {
        return [
            'code' => $link->getCode(),
            'url' => $link->getUrl(),
            'shortUrl' => $this->urlGenerator->generate(
                'link_redirect',
                ['code' => $link->getCode()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'hits' => $link->getHits(),
            'createdAt' => $link->getCreatedAt()->format(\DATE_ATOM),
        ];
    }
}
