<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CreateLinkRequest;
use App\Http\LinkResponseMapper;
use App\Repository\LinkRepository;
use App\Service\LinkCreator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * CRUD for links. The redirect endpoint lives in RedirectController.
 *
 * Request lifecycle, visible: nginx -> public/index.php -> HttpKernel ->
 * router matches one of these attributes -> the DI container builds this
 * controller with its dependencies -> the action returns a Response.
 */
#[Route('/links')]
final class LinkController extends AbstractController
{
    public function __construct(
        private readonly LinkRepository $links,
        private readonly LinkResponseMapper $mapper,
    ) {
    }

    #[Route('', name: 'link_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator, LinkCreator $creator): JsonResponse
    {
        // Step 1: is the body well-formed? (400 if not -- a transport
        // problem, not a validation problem.)
        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\array_key_exists('url', $body) || !\is_string($body['url'])) {
            throw new BadRequestHttpException('Request body must be valid JSON with a "url" field.');
        }

        // Step 2: is the value valid? (422 with per-field errors if not.)
        $dto = new CreateLinkRequest($body['url']);
        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[] = [
                    'field' => $violation->getPropertyPath(), // "url"
                    'message' => $violation->getMessage(),
                ];
            }

            return new JsonResponse(['errors' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Step 3: create (LinkCreator handles code generation + collisions).
        $link = $creator->create($dto->url);

        return new JsonResponse(
            $this->mapper->toArray($link),
            Response::HTTP_CREATED,
            ['Location' => $this->generateUrl('link_show', ['code' => $link->getCode()])],
        );
    }

    #[Route('', name: 'link_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $links = array_map(
            $this->mapper->toArray(...),
            $this->links->findAllNewestFirst(),
        );

        return new JsonResponse(['links' => $links]);
    }

    // The `requirements` regex makes the router itself reject anything that
    // can't be a code (e.g. a short URL mangled in transit with a smart quote
    // or ellipsis): a clean routing 404 instead of ever reaching the DB,
    // whose ascii_bin `code` column can't compare against non-ASCII input.
    #[Route('/{code}', name: 'link_show', requirements: ['code' => '[0-9A-Za-z]+'], methods: ['GET'])]
    public function show(string $code): JsonResponse
    {
        $link = $this->links->findOneByCode($code)
            ?? throw new NotFoundHttpException('Link not found.');

        return new JsonResponse($this->mapper->toArray($link));
    }

    #[Route('/{code}', name: 'link_delete', requirements: ['code' => '[0-9A-Za-z]+'], methods: ['DELETE'])]
    public function delete(string $code): Response
    {
        $link = $this->links->findOneByCode($code)
            ?? throw new NotFoundHttpException('Link not found.');

        $this->links->remove($link);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
