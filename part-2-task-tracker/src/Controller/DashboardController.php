<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\TeamSummaryProvider;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\TaskRepository;
use App\Repository\TeamMembershipRepository;
use App\Repository\TeamRepository;
use App\Security\Voter\TeamVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The server-rendered dashboard (session-only: the web firewall redirects
 * anonymous browsers to /login; API tokens do NOT work here -- they only
 * exist on the ^/api firewall).
 *
 * This is where user-authored content (team names, task titles/descriptions,
 * comment bodies) is rendered into HTML. Twig autoescaping (on by default,
 * no |raw anywhere in the project) is the XSS defense; SecurityHeadersListener
 * adds the CSP belt-and-suspenders around it.
 */
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TeamSummaryProvider $summaries,
        private readonly TeamRepository $teams,
    ) {
    }

    #[Route('/dashboard', name: 'app_dashboard', methods: ['GET'])]
    public function index(TeamMembershipRepository $memberships): Response
    {
        $user = $this->currentUser();

        $rows = [];
        foreach ($memberships->findByUserWithTeams($user) as $membership) {
            $rows[] = [
                'membership' => $membership,
                // Read through the Redis cache (team_summary.{id}, TTL 300s).
                'summary' => $this->summaries->summaryFor($membership->getTeam()),
            ];
        }

        return $this->render('dashboard/index.html.twig', ['rows' => $rows]);
    }

    #[Route('/dashboard/teams/{id}', name: 'app_dashboard_team', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function team(int $id, TaskRepository $tasks, CommentRepository $comments): Response
    {
        // Same 404 policy as the API: a team you cannot view "does not exist".
        $team = $this->teamOr404($id);

        // Tasks + comments are fetched fresh (comments are deliberately
        // outside every cached payload); only the summary counts are cached.
        $teamTasks = $tasks->findByTeamNewestFirst($team);

        return $this->render('dashboard/team.html.twig', [
            'team' => $team,
            'summary' => $this->summaries->summaryFor($team),
            'tasks' => $teamTasks,
            'commentsByTask' => $comments->findByTasksGroupedOldestFirst($teamTasks),
        ]);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function teamOr404(int $id): Team
    {
        $team = $this->teams->find($id);
        if ($team === null || !$this->isGranted(TeamVoter::VIEW, $team)) {
            throw new NotFoundHttpException('Team not found.');
        }

        return $team;
    }
}
