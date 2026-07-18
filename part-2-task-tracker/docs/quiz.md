# Part 2 quiz — team task tracker

Curriculum step 6. Every question is grounded in the actual code in this
directory — when a question names a file, class, or line, open it. The point
is not recall; it is being able to defend *why the code is shaped the way it
is*, and to predict what breaks when the shape changes.

Answers (with file references) are in [quiz-answers.md](quiz-answers.md).
Attempt everything before looking.

---

## Authentication vs. authorization

**Q1.** In this app, *authentication* ("who is this?") and *authorization*
("may they do this?") live in completely different places. Name where each
one lives — specific config and classes — and explain why Symfony wants them
split at exactly those two points rather than, say, both checked at the top
of each controller action.

**Q2.** A client holds a perfectly valid API token and sends
`GET /dashboard` with `Authorization: Bearer tt2_...`. Predict the exact
response (status code and where the client ends up), then explain *why* the
token is worthless on that route even though it authenticates the same user
the dashboard would show. Which test in `tests/Api/DashboardCest.php` proves
this?

**Q3.** `POST /api/tokens` and `POST /api/register` appear in
`security.yaml`'s `access_control` with `PUBLIC_ACCESS`, *above* the
`^/api → ROLE_USER` rule. Why must they be public, why must they come first,
and what would a client see if the ordering were flipped?

## Voters

**Q4.** `TaskController::delete()` contains exactly one line of
authorization:

```php
$this->denyAccessUnlessGranted(TaskVoter::DELETE, $task);
```

Write out (in pseudocode is fine) the inline version this replaces — the
`if` block the controller would need without `TaskVoter` — and then name
three concrete costs the codebase avoids by putting that logic in
`src/Security/Voter/TaskVoter.php` instead. "It's cleaner" is not an answer;
point at things like the §4 permission matrix, `tests/Unit/TaskVoterTest.php`,
and `CommentController`.

**Q5.** `TaskVoter::voteOnAttribute()` decides "is this the task's creator"
with:

```php
$task->getCreatedBy()->getUserIdentifier() === $user->getUserIdentifier()
```

rather than `$task->getCreatedBy() === $user` or comparing `getId()`. The
comment in the file explains it. Why does object identity (or id equality)
break, and *where* does it break first?

**Q6.** `MemberController::remove()` lets any member remove *themself*
without asking `TeamVoter::MANAGE_MEMBERS`, and both `changeRole()` and
`remove()` return a **422**, not a 403, when the change would leave the team
without an admin. The class docblock calls these "business rules, not
authorization rules". What is the distinction being drawn, and why does it
decide *which file* each rule lives in?

**Q7.** `TeamMembershipResolver` memoizes `roleFor()` results per request,
and `MemberController::changeRole()` calls
`$this->membershipResolver->forget($membership->getUser(), $team)` right
after the flush. What problem does the memo solve, and what specific bug
does the `forget()` call prevent?

## API tokens

**Q8.** Walk the life of an API token through `TokenController::issue()` and
`src/Security/ApiTokenHandler.php`: what exactly is generated, what exactly
is stored, and how does a later request turn `Authorization: Bearer tt2_...`
back into a user? Then answer the two "why" halves:

- (a) What concretely breaks — name the attack — if the `api_token` table
  stored the plain token instead of a hash?
- (b) Passwords in this app get argon2id/bcrypt via the `'auto'` hasher, yet
  `ApiToken` deliberately uses plain SHA-256. The entity docblock defends
  this. Why is SHA-256 *correct* here and bcrypt actually *wrong* — give
  both the security argument and the mechanical one (think about the
  `uniq_api_token_hash` index and how `findOneByHash()` works).

**Q9.** `TokenController::issue()` returns the identical
`401 Invalid credentials.` for an unknown email and for a wrong password —
but `MemberController::add()` cheerfully returns
`"No account with this email exists."` for an unknown invitee. Why is the
same disclosure treated as a vulnerability in one endpoint and as good UX in
the other?

## 401 vs. 403 vs. 404

**Q10.** Three requests hit the API. Give the status code each one receives
and the *policy reason* — then identify which class produces each response.

1. `GET /api/teams/5/tasks` with no `Authorization` header at all.
2. `DELETE /api/tasks/9` by an authenticated **member** of the task's team
   who is neither the creator nor an admin.
3. `GET /api/tasks/9` by an authenticated user who is **not a member** of
   the task's team.

Why is case 3 *deliberately* not a 403 — what would a 403 hand to an
attacker, and where in `TaskController` is the choice implemented?

**Q11.** `TaskController::taskOr404()` checks the VIEW permission with
`$this->isGranted(TaskVoter::VIEW, $task)` inside an `if`, while every
action check uses `$this->denyAccessUnlessGranted(...)`. Both consult the
same voters. Why the two different call styles — what different failure does
each one need to produce?

## XSS — Twig vs. JSON

**Q12.** A user stores a task titled `<script>alert('xss')</script>` and a
comment `<img src=x onerror=alert(1)>` (exactly what
`DashboardCest::storedXssPayloadsRenderInert()` does). The team dashboard at
`/dashboard/teams/{id}` renders both. Explain precisely why nothing
executes: name the *primary* mechanism (and the one project-wide fact that
keeps it airtight — grep for it), and the *second* layer from
`SecurityHeadersListener` that would neuter a payload even if the first
layer ever failed.

**Q13.** `GET /api/tasks/{id}` returns those same payload strings
byte-for-byte **raw**, and `SecurityHeadersListener` deliberately skips
every `/api` path — the test even asserts the CSP header is absent there.
Why is raw output the *correct* behavior for the JSON API rather than a
hole, and why does that reasoning stop the moment someone says "we don't
need escaping, we're just an API"?

## Rate limiting

**Q14.** `RateLimitListener` registers the same class twice on
`kernel.request`: `onAuthEndpoint` at priority **16** keyed by
`'ip:'.$clientIp`, and `onApiWrite` at priority **4** keyed by
`'user:'.$user->getId()`, with the security firewall listening in between at
priority 8. For each hook, justify the key choice *and* the side of the
firewall it sits on. Then name the concrete bypass that would open up if the
auth limiter ran *after* the firewall instead — the docblock names the
attack being modeled.

**Q15.** Two smaller design choices in `config/packages/rate_limiter.yaml`:

- (a) Both limiters use `policy: sliding_window`. What exact abuse does a
  `fixed_window` policy permit that sliding closes?
- (b) The counters live in the Redis-backed `cache.rate_limiter` pool rather
  than in PHP memory. What breaks with in-memory counters in this stack —
  and why does `when@test` crank both limits to 10000?

## Caching

**Q16.** List the four write paths that call
`TeamTasksCacheInvalidator::invalidate()`, and the two cached reads the
`team_tasks.{teamId}` tag wipes. Then explain three design decisions the
invalidation scheme encodes:

- (a) why invalidation is by **tag** and not by deleting keys (look at how
  `TaskCacheKeys::taskList()` builds keys);
- (b) why `invalidate()` must run **after** the flush, not before;
- (c) what staleness the design still *accepts*, and why comment writes
  need no invalidation at all.

What user-visible bug is the whole scheme protecting against — the thing
that would happen without it, inside the 60s/300s TTLs?

## The SOLID refactor

**Q17.** `docs/solid-refactor.md` names three principles the stage-3
refactor of `TaskController::list()` exercised. For each of SRP, OCP, and
DIP, give the *symptom in the before-code* (the doc quotes it — the
80-line method, `positiveIntParam(): int|JsonResponse`, the `services.yaml`
decoration…) and the file in `src/Task/` that fixed it. Then name the two
things that measurably got easier afterward — one about tests, one about
adding features — with the specific file each claim points to.

## Jenkins

**Q18.** The pipeline in `Jenkinsfile` runs composer validate → PHP-CS-Fixer
→ PHPStan → Codeception, and every stage can go red. For each of the four
quality stages, give one defect that **only that stage** catches — a bug the
other three would sail past. Then two design details: why does the fixer run
with `--dry-run` in CI when locally it rewrites files, and why does the
Codeception stage bring up its own compose project (`task-tracker-ci`)
instead of testing against the dev stack that is already running?
