<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\TeamTasksCacheInvalidator;
use App\Dto\TeamNameRequest;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Security\TeamMembershipResolver;
use App\Security\Voter\TeamVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Team CRUD. Authorization is voters only -- no inline role checks. The one
 * policy to see here: every lookup goes through teamOr404(), which converts a
 * TEAM_VIEW denial into the same 404 a nonexistent id gets, so outsiders
 * cannot probe which team ids exist.
 */
#[Route('/api/teams')]
final class TeamController extends AbstractController
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMembershipRepository $memberships,
        private readonly TeamMembershipResolver $membershipResolver,
        private readonly ApiResponseMapper $mapper,
        private readonly TeamTasksCacheInvalidator $cacheInvalidator,
    ) {
    }

    #[Route('', name: 'api_team_create', methods: ['POST'])]
    public function create(Request $request, ValidatorInterface $validator, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->currentUser();
        $dto = TeamNameRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        // Team + creator's admin membership in ONE flush: no moment exists
        // where the team is persisted but admin-less.
        $team = new Team($dto->name);
        $em->persist($team);
        $em->persist(new TeamMembership($user, $team, TeamRole::Admin));
        $em->flush();

        return new JsonResponse($this->mapper->team($team, TeamRole::Admin), Response::HTTP_CREATED);
    }

    #[Route('', name: 'api_team_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $user = $this->currentUser();

        // Scoped by membership at the query level: teams you don't belong to
        // are never even fetched.
        $teams = array_map(
            fn (TeamMembership $m): array => $this->mapper->team($m->getTeam(), $m->getRole()),
            $this->memberships->findByUserWithTeams($user),
        );

        return new JsonResponse(['teams' => $teams]);
    }

    #[Route('/{id}', name: 'api_team_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->currentUser();
        $team = $this->teamOr404($id);

        $role = $this->membershipResolver->roleFor($user, $team);
        \assert($role instanceof TeamRole); // teamOr404 guaranteed membership

        return new JsonResponse($this->mapper->team($team, $role));
    }

    #[Route('/{id}', name: 'api_team_rename', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function rename(int $id, Request $request, ValidatorInterface $validator, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->currentUser();
        $team = $this->teamOr404($id);
        $this->denyAccessUnlessGranted(TeamVoter::EDIT, $team); // 403 for plain members

        $dto = TeamNameRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $team->rename($dto->name);
        $em->flush();

        $role = $this->membershipResolver->roleFor($user, $team);
        \assert($role instanceof TeamRole);

        return new JsonResponse($this->mapper->team($team, $role));
    }

    #[Route('/{id}', name: 'api_team_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $team = $this->teamOr404($id);
        $this->denyAccessUnlessGranted(TeamVoter::DELETE, $team); // 403 for plain members

        // Memberships, tasks and comments cascade at the database level.
        $teamId = (int) $team->getId();
        $this->teams->remove($team);

        // Deleting the team is a write path that affects its cached task
        // lists and summary -- without this, GETs could serve a deleted
        // team's tasks for up to a TTL (the 404 policy would still hide them
        // from outsiders, but ex-members would see ghosts).
        $this->cacheInvalidator->invalidate($teamId);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    /**
     * The 404 policy: "not found" and "not yours to see" are the same
     * response, so team ids never become an existence oracle for outsiders.
     */
    private function teamOr404(int $id): Team
    {
        $team = $this->teams->find($id);
        if ($team === null || !$this->isGranted(TeamVoter::VIEW, $team)) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $team;
    }
}
