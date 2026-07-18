# The SOLID refactor — the task-list read path

Part 2's deliberate refactor, executed in stage 3. The code below is real:
the "before" excerpts were copied out of `src/Controller/TaskController.php`
immediately before the refactor; the "after" excerpts are the current files.
The whole Api suite (54+ HTTP tests, including every pagination/filter/sort
contract test) stayed green through the change — the external behavior of
`GET /api/teams/{id}/tasks` is byte-identical.

## The smell: one controller method that did everything

This was not staged. Stage 1 wrote the natural first implementation of the
list endpoint — parse params, build the query, paginate, map. Stage 2 then
added the Redis read-through *inside the same method*, because that was the
smallest diff that worked. "Just one more thing" twice, and
`TaskController::list()` ended up owning **five jobs**:

1. HTTP parameter parsing + validation (six params, each with a loud-422 rule)
2. the sort whitelist and its DQL mapping
3. cache key construction and the read-through
4. the Doctrine query build
5. pagination math + response mapping

The method, verbatim, before the refactor (from the pre-refactor copy of
`TaskController.php`; comments elided in the middle for length — nothing
else removed):

```php
#[Route('/api/teams/{id}/tasks', name: 'api_task_list', requirements: ['id' => '\d+'], methods: ['GET'])]
public function list(int $id, Request $request): JsonResponse
{
    $team = $this->teamOr404($id);

    // --- query-param parsing & validation -----------------------------
    $query = $request->query;

    $page = $this->positiveIntParam($query->get('page'), 'page', min: 1, default: 1);
    $limit = $this->positiveIntParam($query->get('limit'), 'limit', min: 1, default: 20, max: 100);
    if ($page instanceof JsonResponse) {
        return $page;
    }
    if ($limit instanceof JsonResponse) {
        return $limit;
    }

    $statusParam = $query->get('status');
    $status = null;
    if ($statusParam !== null) {
        $status = TaskStatus::tryFrom($statusParam);
        if ($status === null) {
            return ApiProblem::validationError('status', 'Status must be "todo", "in_progress" or "done".');
        }
    }

    $assigneeParam = $query->get('assignee');
    $assigneeId = null; // int = filter by user id; 'none' = unassigned only
    $unassignedOnly = false;
    if ($assigneeParam !== null) {
        if ($assigneeParam === 'none') {
            $unassignedOnly = true;
        } elseif (ctype_digit($assigneeParam) && $assigneeParam !== '0') {
            $assigneeId = (int) $assigneeParam;
        } else {
            return ApiProblem::validationError('assignee', 'Assignee must be a user id or "none".');
        }
    }

    $sort = $query->get('sort') ?? 'createdAt';
    if (!\array_key_exists($sort, self::SORT_MAP)) {
        return ApiProblem::validationError('sort', 'Sort must be one of: createdAt, dueDate, priority, status.');
    }

    $direction = $query->get('direction') ?? 'desc';
    if (!\in_array($direction, ['asc', 'desc'], true)) {
        return ApiProblem::validationError('direction', 'Direction must be "asc" or "desc".');
    }

    // --- cache read-through -------------------------------------------
    $cacheKey = TaskCacheKeys::taskList($id, $page, $limit, $statusParam, $assigneeParam, $sort, $direction);

    $payload = $this->tasksCache->get(
        $cacheKey,
        function (ItemInterface $item) use ($id, $team, $page, $limit, $status, $unassignedOnly, $assigneeId, $sort, $direction): array {
            $item->expiresAfter($this->taskListTtl);
            $item->tag(TaskCacheKeys::teamTasksTag($id));

            // --- the Doctrine query -----------------------------------
            $qb = $this->tasks->createQueryBuilder('t')
                ->addSelect('assignee', 'createdBy')
                ->leftJoin('t.assignee', 'assignee')
                ->join('t.createdBy', 'createdBy')
                ->addSelect("CASE t.priority WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END AS HIDDEN priorityRank")
                ->andWhere('t.team = :team')
                ->setParameter('team', $team);

            if ($status !== null) {
                $qb->andWhere('t.status = :status')->setParameter('status', $status);
            }
            if ($unassignedOnly) {
                $qb->andWhere('t.assignee IS NULL');
            } elseif ($assigneeId !== null) {
                $qb->andWhere('IDENTITY(t.assignee) = :assigneeId')->setParameter('assigneeId', $assigneeId);
            }

            $qb->orderBy(self::SORT_MAP[$sort], $direction)
                ->addOrderBy('t.id', $direction) // deterministic tiebreak (DATETIME has second precision)
                ->setFirstResult(($page - 1) * $limit)
                ->setMaxResults($limit);

            $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
            $total = \count($paginator);

            return [
                'tasks' => array_map($this->mapper->task(...), iterator_to_array($paginator->getIterator())),
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => (int) ceil($total / $limit),
            ];
        },
    );

    return new JsonResponse($payload);
}
```

Two supporting pieces lived in the same class and made the smell worse:

```php
// A "parse an int" helper whose return type is int **or an HTTP response**
// -- the validation layer and the HTTP layer fused into one signature:
private function positiveIntParam(?string $raw, string $name, int $min, int $default, ?int $max = null): int|JsonResponse

// And cache-tag knowledge, duplicated across controllers: TaskController
// had this private method, and TeamController::delete() called
// $this->tasksCache->invalidateTags([TaskCacheKeys::teamTasksTag($teamId)])
// inline -- two HTTP classes both knowing how the cache is wired:
private function invalidateTeamTasks(int $teamId): void
{
    $this->tasksCache->invalidateTags([TaskCacheKeys::teamTasksTag($teamId)]);
}
```

## The principles violated, by name

- **SRP (Single Responsibility).** A controller's job is translating
  HTTP ↔ domain. This one also owned validation rules, cache mechanics, and
  query construction — four reasons to change, one class. Symptoms you could
  point at: an 80-line method, a closure capturing nine variables, and
  `positiveIntParam()` returning `int|JsonResponse` because parsing and
  responding had merged.
- **OCP (Open/Closed).** Stage 2 added caching *by editing the query code* —
  wrapping the existing body in `$this->tasksCache->get(...)`. Every future
  concern (metrics? a different cache? a search backend?) would mean editing
  this method again.
- **DIP (Dependency Inversion).** The controller depended on the concrete
  mechanics of everything: `TagAwareCacheInterface`, `TaskCacheKeys`, the
  TTL parameter, the Doctrine `Paginator`. Nothing stood between "HTTP
  request arrives" and "Redis key is hashed".

Testability was the tell: the validation table (page ≥ 1, limit 1–100, the
sort whitelist, `assignee=none`…) could only be exercised through full HTTP
kernel tests, because the rules were welded to `Request` handling inside a
controller that needs the container, the DB, and Redis to boot.

## The refactor

Three extractions, one decorator, no behavior change:

| New file | Job it took from the controller |
|---|---|
| `src/Task/TaskListQuery.php` | immutable value object; `fromRequest()` owns all six param rules and throws `InvalidTaskListQuery($field, $message)` |
| `src/Task/TaskListProviderInterface.php` | the abstraction the controller now depends on |
| `src/Task/DoctrineTaskListProvider.php` | the DQL build (sort whitelist → explicit expressions), pagination, mapping |
| `src/Task/CachingTaskListProvider.php` | **decorator** implementing the same interface; owns key/tag/TTL |
| `src/Cache/TeamTasksCacheInvalidator.php` | the write-path half: the one `invalidateTags` call both TaskController and TeamController now share |

### After: the controller action

```php
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
```

Twelve lines: authorize, parse, delegate, respond. The `int|JsonResponse`
union is gone — `TaskListQuery` throws a small domain exception carrying the
field name, and *converting that to a 422* is the one HTTP thing the
controller still does (deliberately: the query object knows nothing about
responses).

### After: the seam

```php
interface TaskListProviderInterface
{
    /**
     * @return array{tasks: list<array<string, mixed>>, page: int, limit: int, total: int, pages: int}
     */
    public function list(Team $team, TaskListQuery $query): array;
}
```

### After: caching as a decorator (excerpt)

```php
final class CachingTaskListProvider implements TaskListProviderInterface
{
    public function __construct(
        private readonly TaskListProviderInterface $inner,
        private readonly TagAwareCacheInterface $tasksCache,
        #[Autowire('%app.cache.task_list_ttl%')]
        private readonly int $ttl,
    ) {
    }

    public function list(Team $team, TaskListQuery $query): array
    {
        // ... TaskCacheKeys::taskList(...) built from the validated query ...
        return $this->tasksCache->get($key, function (ItemInterface $item) use ($team, $teamId, $query): array {
            $item->expiresAfter($this->ttl);
            $item->tag(TaskCacheKeys::teamTasksTag($teamId));

            return $this->inner->list($team, $query);   // cache miss -> MySQL
        });
    }
}
```

### After: the wiring (`config/services.yaml`)

```yaml
# TaskController type-hints TaskListProviderInterface and knows nothing
# else. The alias resolves it to the plain Doctrine provider, and the
# caching provider DECORATES that: Symfony hands the caching wrapper to
# whoever asks for the interface, with the original Doctrine provider
# injected as its inner. Turning caching off (or swapping Redis for
# something else) is an edit to THIS block -- no class changes (OCP/DIP).
App\Task\TaskListProviderInterface: '@App\Task\DoctrineTaskListProvider'

App\Task\CachingTaskListProvider:
    decorates: App\Task\DoctrineTaskListProvider
    arguments:
        $inner: '@.inner'
```

This is where OCP/DIP stop being slogans: **the caching feature is now a DI
configuration fact, not a property of the query code.** Delete the two
`services.yaml` lines and the app runs uncached; the Doctrine provider is
untouched either way.

## What got easier — concretely

- **The validation table became a unit test.** `tests/Unit/TaskListQueryTest.php`
  now drives every param rule (defaults, `assignee=none`, six families of bad
  values asserting *which field* each 422 blames) as pure PHP against
  `new Request([...])` — no kernel, no DB, milliseconds. Before the refactor
  this table only existed as HTTP tests, which still exist (`TaskListCest`)
  but now guard the contract rather than being the only way to reach the logic.
- **Cache invalidation has one home.** `TeamTasksCacheInvalidator` is the
  single class that knows writes must wipe the `team_tasks.{teamId}` tag;
  TaskController (create/update/delete) and TeamController (delete) call the
  same method instead of each holding a cache pool and a key builder. The
  "every write path must invalidate, and the one you forget is the bug"
  lesson from the PRD now has exactly one place to audit.
- **The next feature has an obvious seam.** A new sort field is one entry in
  `TaskListQuery::SORT_FIELDS` + one in `DoctrineTaskListProvider::SORT_DQL`;
  a metrics/logging concern is another decorator in `services.yaml`; swapping
  MySQL for a search index is a second `TaskListProviderInterface`
  implementation. None of those touch the controller.
- **The types got honest.** `int|JsonResponse` is gone; the closure capturing
  nine locals is gone (the query object *is* those locals, with a name).

## What deliberately did not change

- The HTTP contract: same 200 payloads, same 422 bodies, same field names —
  the Api suite is the proof.
- Cache keys: `TaskListQuery::statusValue()/assigneeValue()` reproduce the
  exact raw-param strings the old code hashed, so a deploy of this refactor
  would not even cold-start the cache.
- `TeamSummaryProvider` (the dashboard's cached counts) already had the
  right shape — a single-purpose provider — so it stayed as-is; it shares
  the tag, so the new invalidator covers it too.
