<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\LinkRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public-facing redirect: GET /r/{code}.
 *
 * 302 (temporary), NOT 301: browsers cache 301s aggressively and would skip
 * the server on repeat visits -- hit counting would silently break.
 *
 * Doesn't extend AbstractController (it needs none of its helpers), so the
 * #[AsController] attribute marks it for controller argument resolution.
 */
#[AsController]
final class RedirectController
{
    public function __construct(private readonly LinkRepository $links)
    {
    }

    // requirements: only base62 can be a code; anything else (say, a URL
    // mangled with a non-ASCII character) 404s at the router instead of
    // hitting the ascii_bin `code` column with an incomparable string.
    #[Route('/r/{code}', name: 'link_redirect', requirements: ['code' => '[0-9A-Za-z]+'], methods: ['GET'])]
    public function __invoke(string $code): RedirectResponse
    {
        $link = $this->links->findOneByCode($code)
            ?? throw new NotFoundHttpException('Link not found.');

        // Counted before the response goes out; atomic in SQL (hits = hits + 1).
        $this->links->incrementHits($code);

        return new RedirectResponse($link->getUrl(), RedirectResponse::HTTP_FOUND);
    }
}
