<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Enum\TaskStatus;
use App\Task\InvalidTaskListQuery;
use App\Task\TaskListQuery;
use Codeception\Test\Unit;
use Symfony\Component\HttpFoundation\Request;

/**
 * The task-list query contract as a pure-logic table: which parameter values
 * produce a query object, which throw, and which field each violation names.
 * (The same rules are exercised over HTTP in TaskListCest; here they run
 * without a kernel, which is exactly what extracting fromRequest() bought.).
 */
final class TaskListQueryTest extends Unit
{
    public function testDefaultsWhenNoParamsGiven(): void
    {
        $query = TaskListQuery::fromRequest(new Request());

        $this->assertSame(1, $query->page);
        $this->assertSame(20, $query->limit);
        $this->assertNull($query->status);
        $this->assertNull($query->assigneeId);
        $this->assertFalse($query->unassignedOnly);
        $this->assertSame('createdAt', $query->sort);
        $this->assertSame('desc', $query->direction);
    }

    public function testEveryParameterParsesWhenValid(): void
    {
        $query = TaskListQuery::fromRequest(new Request([
            'page' => '3',
            'limit' => '50',
            'status' => 'in_progress',
            'assignee' => '7',
            'sort' => 'priority',
            'direction' => 'asc',
        ]));

        $this->assertSame(3, $query->page);
        $this->assertSame(50, $query->limit);
        $this->assertSame(TaskStatus::InProgress, $query->status);
        $this->assertSame(7, $query->assigneeId);
        $this->assertFalse($query->unassignedOnly);
        $this->assertSame('priority', $query->sort);
        $this->assertSame('asc', $query->direction);
    }

    public function testAssigneeNoneMeansUnassignedOnly(): void
    {
        $query = TaskListQuery::fromRequest(new Request(['assignee' => 'none']));

        $this->assertTrue($query->unassignedOnly);
        $this->assertNull($query->assigneeId);
        $this->assertSame('none', $query->assigneeValue());
    }

    public function testCacheValueFormsMirrorTheRawParams(): void
    {
        $query = TaskListQuery::fromRequest(new Request(['status' => 'done', 'assignee' => '42']));

        $this->assertSame('done', $query->statusValue());
        $this->assertSame('42', $query->assigneeValue());

        $bare = TaskListQuery::fromRequest(new Request());
        $this->assertNull($bare->statusValue());
        $this->assertNull($bare->assigneeValue());
    }

    /**
     * The whole rejection table: anything outside the contract throws,
     * naming the offending parameter -- never a silent fallback.
     */
    public function testInvalidValuesThrowNamingTheParameter(): void
    {
        $cases = [
            // param        bad values
            'page' => ['0', '-1', 'abc', '1.5', ''],
            'limit' => ['0', '101', 'ten', '-5'],
            'status' => ['finished', 'TODO', ''],
            'assignee' => ['0', 'someone', '-3', ''],
            'sort' => ['title', 'CreatedAt', 'id; DROP TABLE task', ''],
            'direction' => ['up', 'DESC', ''],
        ];

        foreach ($cases as $param => $badValues) {
            foreach ($badValues as $bad) {
                try {
                    TaskListQuery::fromRequest(new Request([$param => $bad]));
                    $this->fail(sprintf('%s=%s should have been rejected', $param, $bad));
                } catch (InvalidTaskListQuery $e) {
                    $this->assertSame($param, $e->field, sprintf('%s=%s must blame "%s"', $param, $bad, $param));
                    $this->assertNotSame('', $e->getMessage());
                }
            }
        }
    }
}
