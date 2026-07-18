<?php

declare(strict_types=1);

namespace App\Task;

/**
 * Thrown by TaskListQuery::fromRequest() when a query parameter falls outside
 * the contract. Carries the offending parameter name so the HTTP layer can
 * render the standard 422 shape with `field` set.
 *
 * Deliberately NOT an HttpException: the query object reports *what* was
 * invalid; turning that into a response stays the controller's job.
 */
final class InvalidTaskListQuery extends \InvalidArgumentException
{
    public function __construct(
        public readonly string $field,
        string $message,
    ) {
        parent::__construct($message);
    }
}
