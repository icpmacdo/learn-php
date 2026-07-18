<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\IssueTokenRequest;
use App\Entity\ApiToken;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * API token lifecycle: issue (credential exchange), list, revoke.
 */
#[Route('/api/tokens')]
final class TokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly ApiResponseMapper $mapper,
    ) {
    }

    #[Route('', name: 'api_token_issue', methods: ['POST'])]
    public function issue(
        Request $request,
        ValidatorInterface $validator,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $dto = IssueTokenRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        // Deliberately identical 401 for "unknown email" and "wrong password":
        // the response must not become a user-enumeration oracle.
        $user = $users->findOneByEmail($dto->email);
        if ($user === null || !$hasher->isPasswordValid($user, $dto->password)) {
            throw new UnauthorizedHttpException('Bearer', 'Invalid credentials.');
        }

        // CSPRNG, 256 bits. Only the SHA-256 hash is persisted; the plain
        // token exists in this one response and never again server-side.
        $plainToken = 'tt2_'.bin2hex(random_bytes(32));
        $token = new ApiToken($user, hash('sha256', $plainToken), $dto->name);
        $this->tokens->save($token);

        return new JsonResponse([
            'id' => $token->getId(),
            'name' => $token->getName(),
            'token' => $plainToken,
            'createdAt' => $token->getCreatedAt()->format(\DATE_ATOM),
        ], Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_token_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return new JsonResponse([
            'tokens' => array_map($this->mapper->apiToken(...), $this->tokens->findByUser($user)),
        ]);
    }

    #[Route('/{id}', name: 'api_token_revoke', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function revoke(int $id): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Scoped lookup: someone else's token id is indistinguishable from a
        // nonexistent one -- 404 either way.
        $token = $this->tokens->findOneByIdAndUser($id, $user)
            ?? throw new NotFoundHttpException('Token not found.');

        $this->tokens->remove($token);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
