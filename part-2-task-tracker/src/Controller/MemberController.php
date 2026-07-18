<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\AddMemberRequest;
use App\Dto\ChangeRoleRequest;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Enum\TeamRole;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
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
 * Team membership management. Voter-gated (TEAM_MANAGE_MEMBERS = admin only)
 * with two carve-outs that are BUSINESS rules, not authorization rules, so
 * they live here rather than in the voter:
 *
 *  - anyone may remove THEMSELF (leaving a team is not managing it);
 *  - the last admin can never be demoted or removed (422, not 403 -- the
 *    caller is allowed to manage members; this particular change is invalid).
 */
#[Route('/api/teams/{id}/members', requirements: ['id' => '\d+'])]
final class MemberController extends AbstractController
{
    public function __construct(
        private readonly TeamRepository $teams,
        private readonly TeamMembershipRepository $memberships,
        private readonly TeamMembershipResolver $membershipResolver,
        private readonly ApiResponseMapper $mapper,
    ) {
    }

    #[Route('', name: 'api_member_list', methods: ['GET'])]
    public function list(int $id): JsonResponse
    {
        $team = $this->teamOr404($id);

        return new JsonResponse([
            'members' => array_map($this->mapper->member(...), $this->memberships->findByTeamWithUsers($team)),
        ]);
    }

    #[Route('', name: 'api_member_add', methods: ['POST'])]
    public function add(int $id, Request $request, ValidatorInterface $validator, UserRepository $users): JsonResponse
    {
        $team = $this->teamOr404($id);
        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team); // 403 for plain members

        $dto = AddMemberRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        // Inviting requires an existing account (no invitation emails in this
        // part). Unlike login, saying "this email is unknown" is fine here:
        // the caller is an authenticated team admin typing a colleague's
        // address, not an anonymous attacker probing accounts.
        $invitee = $users->findOneByEmail($dto->email);
        if ($invitee === null) {
            return ApiProblem::validationError('email', 'No account with this email exists.');
        }

        if ($this->memberships->findOneByUserAndTeam($invitee, $team) !== null) {
            return ApiProblem::validationError('email', 'This user is already a member of the team.');
        }

        $membership = new TeamMembership($invitee, $team, TeamRole::from($dto->role));
        $this->memberships->save($membership);

        return new JsonResponse($this->mapper->member($membership), Response::HTTP_CREATED);
    }

    #[Route('/{userId}', name: 'api_member_change_role', requirements: ['userId' => '\d+'], methods: ['PATCH'])]
    public function changeRole(
        int $id,
        int $userId,
        Request $request,
        ValidatorInterface $validator,
        EntityManagerInterface $em,
    ): JsonResponse {
        $team = $this->teamOr404($id);
        $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);

        $dto = ChangeRoleRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $membership = $this->membershipOr404($team, $userId);
        $newRole = TeamRole::from($dto->role);

        if (
            $membership->getRole() === TeamRole::Admin
            && $newRole !== TeamRole::Admin
            && $this->memberships->countAdmins($team) <= 1
        ) {
            return ApiProblem::validationError('role', 'A team must keep at least one admin.');
        }

        $membership->setRole($newRole);
        $em->flush();
        // Voters may re-check this user later in the request; drop the memo.
        $this->membershipResolver->forget($membership->getUser(), $team);

        return new JsonResponse($this->mapper->member($membership));
    }

    #[Route('/{userId}', name: 'api_member_remove', requirements: ['userId' => '\d+'], methods: ['DELETE'])]
    public function remove(int $id, int $userId): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        $team = $this->teamOr404($id);

        // Removing yourself is leaving -- allowed for any member. Removing
        // anyone else is managing members -- admins only.
        if ($userId !== $user->getId()) {
            $this->denyAccessUnlessGranted(TeamVoter::MANAGE_MEMBERS, $team);
        }

        $membership = $this->membershipOr404($team, $userId);

        if ($membership->getRole() === TeamRole::Admin && $this->memberships->countAdmins($team) <= 1) {
            return ApiProblem::validationError(null, 'A team must keep at least one admin.');
        }

        $this->memberships->remove($membership);
        $this->membershipResolver->forget($membership->getUser(), $team);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    private function teamOr404(int $id): Team
    {
        $team = $this->teams->find($id);
        if ($team === null || !$this->isGranted(TeamVoter::VIEW, $team)) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $team;
    }

    private function membershipOr404(Team $team, int $userId): TeamMembership
    {
        $membership = $this->memberships->findOneBy(['team' => $team, 'user' => $userId]);

        return $membership ?? throw new NotFoundHttpException('Member not found.');
    }
}
