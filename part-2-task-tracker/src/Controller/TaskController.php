<?php

declare(strict_types=1);

namespace App\Controller;

use App\Cache\TeamTasksCacheInvalidator;
use App\Dto\CreateTaskRequest;
use App\Dto\UpdateTaskRequest;
use App\Entity\Task;
use App\Entity\Team;
use App\Entity\User;
use App\Enum\TaskPriority;
use App\Enum\TaskStatus;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\CommentRepository;
use App\Repository\TaskRepository;
use App\Repository\TeamRepository;
use App\Repository\UserRepository;
use App\Security\TeamMembershipResolver;
use App\Security\Voter\TaskVoter;
use App\Security\Voter\TeamVoter;
use App\Task\InvalidTaskListQuery;
use App\Task\TaskListProviderInterface;
use App\Task\TaskListQuery;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Task CRUD. Same voter-only authorization and 404 policy as TeamController.
 *
 * list() once held query parsing, the sort whitelist, cache keys, the cache
 * read-through, pagination math, the Doctrine query and response mapping in
 * one accreted method; the stage-3 SOLID refactor (docs/solid-refactor.md)
 * moved all of it behind TaskListQuery + TaskListProviderInterface, so this
 * controller is back to translating HTTP <-> domain and nothing else.
 */
final class TaskController extends AbstractController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TeamRepository $teams,
        private readonly ApiResponseMapper $mapper,
        private readonly TeamMembershipResolver $membershipResolver,
        private readonly TeamTasksCacheInvalidator $cacheInvalidator,
    ) {
    }

    #[Route('/api/teams/{id}/tasks', name: 'api_task_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, ValidatorInterface $validator, UserRepository $users): JsonResponse
    {
        $user = $this->currentUser();
        $team = $this->teamOr404($id);
        $this->denyAccessUnlessGranted(TeamVoter::CREATE_TASK, $team);

        $dto = CreateTaskRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $task = new Task($team, $dto->title, $user);
        $task->setDescription($dto->description);
        if ($dto->status !== null) {
            $task->setStatus(TaskStatus::from($dto->status));
        }
        if ($dto->priority !== null) {
            $task->setPriority(TaskPriority::from($dto->priority));
        }
        if ($dto->assigneeId !== null) {
            $assignee = $this->resolveAssignee($dto->assigneeId, $team, $users);
            if ($assignee === null) {
                return ApiProblem::validationError('assigneeId', 'Assignee must be a member of the team.');
            }
            $task->setAssignee($assignee);
        }
        if ($dto->dueDate !== null) {
            $task->setDueDate(new \DateTimeImmutable($dto->dueDate));
        }

        $this->tasks->save($task);
        $this->cacheInvalidator->invalidate($id);

        return new JsonResponse($this->mapper->task($task), Response::HTTP_CREATED);
    }

    #[Route('/api/teams/{id}/tasks', name: 'api_task_list', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function list(int $id, Request $request, TaskListProviderInterface $taskList): JsonResponse
    {
        $team = $this->teamOr404($id);

        try {
            $query = TaskListQuery::fromRequest($request);
        } catch (InvalidTaskListQuery $e) {
            return ApiProblem::validationError($e->field, $e->getMessage());
        }

        return new JsonResponse($taskList->list($team, $query));
    }

    #[Route('/api/tasks/{id}', name: 'api_task_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, CommentRepository $comments): JsonResponse
    {
        $task = $this->taskOr404($id);

        return new JsonResponse($this->mapper->task($task) + [
            'comments' => array_map($this->mapper->comment(...), $comments->findByTaskOldestFirst($task)),
        ]);
    }

    #[Route('/api/tasks/{id}', name: 'api_task_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(
        int $id,
        Request $request,
        ValidatorInterface $validator,
        UserRepository $users,
        EntityManagerInterface $em,
    ): JsonResponse {
        $task = $this->taskOr404($id);
        $this->denyAccessUnlessGranted(TaskVoter::EDIT, $task);

        $dto = UpdateTaskRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        if ($dto->title !== null) {
            $task->setTitle($dto->title);
        }
        if ($dto->descriptionProvided) {
            $task->setDescription($dto->description);
        }
        if ($dto->status !== null) {
            $task->setStatus(TaskStatus::from($dto->status));
        }
        if ($dto->priority !== null) {
            $task->setPriority(TaskPriority::from($dto->priority));
        }
        if ($dto->assigneeIdProvided) {
            if ($dto->assigneeId === null) {
                $task->setAssignee(null); // explicit null = unassign
            } else {
                $assignee = $this->resolveAssignee($dto->assigneeId, $task->getTeam(), $users);
                if ($assignee === null) {
                    return ApiProblem::validationError('assigneeId', 'Assignee must be a member of the team.');
                }
                $task->setAssignee($assignee);
            }
        }
        if ($dto->dueDateProvided) {
            $task->setDueDate($dto->dueDate !== null ? new \DateTimeImmutable($dto->dueDate) : null);
        }

        $em->flush();
        $this->cacheInvalidator->invalidate((int) $task->getTeam()->getId());

        return new JsonResponse($this->mapper->task($task));
    }

    #[Route('/api/tasks/{id}', name: 'api_task_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $task = $this->taskOr404($id);
        // Creator or team admin; a member deleting someone else's task -> 403.
        $this->denyAccessUnlessGranted(TaskVoter::DELETE, $task);

        $teamId = (int) $task->getTeam()->getId();
        $this->tasks->remove($task); // comments cascade
        $this->cacheInvalidator->invalidate($teamId);

        return new Response(status: Response::HTTP_NO_CONTENT);
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

    /** Same 404 policy as teams: a task in someone else's team "does not exist". */
    private function taskOr404(int $id): Task
    {
        $task = $this->tasks->find($id);
        if ($task === null || !$this->isGranted(TaskVoter::VIEW, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }

        return $task;
    }

    /** An assignee must exist AND be a member -- one 422 either way (an id outside the team is none of the caller's business). */
    private function resolveAssignee(int $assigneeId, Team $team, UserRepository $users): ?User
    {
        $assignee = $users->find($assigneeId);
        if ($assignee === null || !$this->membershipResolver->isMember($assignee, $team)) {
            return null;
        }

        return $assignee;
    }
}
