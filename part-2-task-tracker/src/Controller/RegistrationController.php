<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\RegisterRequest;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ApiResponseMapper $mapper,
    ) {
    }

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        ValidatorInterface $validator,
        UserPasswordHasherInterface $hasher,
    ): JsonResponse {
        $dto = RegisterRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $user = new User($dto->email, $dto->displayName);
        // The hasher needs the user object (to pick its configured algorithm),
        // which is why the hash is set after construction.
        $user->setPasswordHash($hasher->hashPassword($user, $dto->password));

        try {
            $this->users->save($user);
        } catch (UniqueConstraintViolationException) {
            // The unique index is the source of truth for email uniqueness --
            // no racy pre-check SELECT (part 1's collision lesson).
            return ApiProblem::validationError('email', 'This email is already registered.');
        }

        return new JsonResponse($this->mapper->user($user), Response::HTTP_CREATED);
    }
}
