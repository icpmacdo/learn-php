<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\CommentBodyRequest;
use App\Entity\Comment;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Http\ApiResponseMapper;
use App\Http\JsonBody;
use App\Repository\CommentRepository;
use App\Repository\TaskRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\TaskVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Comments. Visibility rides on the task (TASK_VIEW -> 404 for outsiders);
 * editing is author-only, deletion is author-or-admin (moderation).
 */
final class CommentController extends AbstractController
{
    public function __construct(
        private readonly CommentRepository $comments,
        private readonly ApiResponseMapper $mapper,
    ) {
    }

    #[Route('/api/tasks/{id}/comments', name: 'api_comment_create', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function create(int $id, Request $request, ValidatorInterface $validator, TaskRepository $tasks): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        // Same 404 policy: outsiders can't tell this task exists.
        $task = $tasks->find($id);
        if ($task === null || !$this->isGranted(TaskVoter::VIEW, $task)) {
            throw new NotFoundHttpException('Task not found.');
        }
        $this->denyAccessUnlessGranted(TaskVoter::COMMENT, $task);

        $dto = CommentBodyRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $comment = new Comment($task, $user, $dto->body);
        $this->comments->save($comment);

        return new JsonResponse($this->mapper->comment($comment), Response::HTTP_CREATED);
    }

    #[Route('/api/comments/{id}', name: 'api_comment_update', requirements: ['id' => '\d+'], methods: ['PATCH'])]
    public function update(int $id, Request $request, ValidatorInterface $validator, EntityManagerInterface $em): JsonResponse
    {
        $comment = $this->commentOr404($id);
        $this->denyAccessUnlessGranted(CommentVoter::EDIT, $comment); // author only -- admins moderate by delete

        $dto = CommentBodyRequest::fromArray(JsonBody::decode($request));

        $violations = $validator->validate($dto);
        if (\count($violations) > 0) {
            return ApiProblem::fromViolations($violations);
        }

        $comment->setBody($dto->body);
        $em->flush();

        return new JsonResponse($this->mapper->comment($comment));
    }

    #[Route('/api/comments/{id}', name: 'api_comment_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function delete(int $id): Response
    {
        $comment = $this->commentOr404($id);
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment); // author or team admin

        $this->comments->remove($comment);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /** Visible if and only if you can view the task it hangs off. */
    private function commentOr404(int $id): Comment
    {
        $comment = $this->comments->find($id);
        if ($comment === null || !$this->isGranted(TaskVoter::VIEW, $comment->getTask())) {
            throw new NotFoundHttpException('Comment not found.');
        }

        return $comment;
    }
}
