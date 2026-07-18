<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\ApiToken;
use App\Entity\Comment;
use App\Entity\Task;
use App\Entity\Team;
use App\Entity\TeamMembership;
use App\Entity\User;
use App\Enum\TeamRole;

/**
 * Maps entities to their JSON representations, by hand -- no serializer
 * magic, so every response shape is visible in one place (part 1 convention).
 *
 * Timestamps are ATOM UTC; dueDate is a bare Y-m-d (a date, not a moment).
 */
final class ApiResponseMapper
{
    /**
     * Email is included because every context that renders a user (member
     * lists, assignees, authors, /api/me) is only ever shown to fellow team
     * members or the user themself.
     *
     * @return array{id: int|null, email: string, displayName: string}
     */
    public function user(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
        ];
    }

    /** @return array{id: int|null, name: string, createdAt: string, myRole: string} */
    public function team(Team $team, TeamRole $myRole): array
    {
        return [
            'id' => $team->getId(),
            'name' => $team->getName(),
            'createdAt' => $team->getCreatedAt()->format(\DATE_ATOM),
            'myRole' => $myRole->value,
        ];
    }

    /** @return array{user: array<string, mixed>, role: string, joinedAt: string} */
    public function member(TeamMembership $membership): array
    {
        return [
            'user' => $this->user($membership->getUser()),
            'role' => $membership->getRole()->value,
            'joinedAt' => $membership->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function task(Task $task): array
    {
        return [
            'id' => $task->getId(),
            'teamId' => $task->getTeam()->getId(),
            'title' => $task->getTitle(),
            'description' => $task->getDescription(),
            'status' => $task->getStatus()->value,
            'priority' => $task->getPriority()->value,
            'assignee' => $task->getAssignee() !== null ? $this->user($task->getAssignee()) : null,
            'dueDate' => $task->getDueDate()?->format('Y-m-d'),
            'createdBy' => $this->user($task->getCreatedBy()),
            'createdAt' => $task->getCreatedAt()->format(\DATE_ATOM),
            'updatedAt' => $task->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    /** @return array<string, mixed> */
    public function comment(Comment $comment): array
    {
        return [
            'id' => $comment->getId(),
            'taskId' => $comment->getTask()->getId(),
            'author' => $this->user($comment->getAuthor()),
            'body' => $comment->getBody(),
            'createdAt' => $comment->getCreatedAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * Never includes a token value: after issuance the server only knows the
     * hash. (The one-time plain token is added by TokenController::issue.).
     *
     * @return array<string, mixed>
     */
    public function apiToken(ApiToken $token): array
    {
        return [
            'id' => $token->getId(),
            'name' => $token->getName(),
            'createdAt' => $token->getCreatedAt()->format(\DATE_ATOM),
            'lastUsedAt' => $token->getLastUsedAt()?->format(\DATE_ATOM),
        ];
    }
}
