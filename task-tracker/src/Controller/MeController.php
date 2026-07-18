<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Http\ApiResponseMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class MeController extends AbstractController
{
    /** Who am I? -- the canonical "is my token working" endpoint. */
    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(ApiResponseMapper $mapper): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return new JsonResponse($mapper->user($user));
    }
}
