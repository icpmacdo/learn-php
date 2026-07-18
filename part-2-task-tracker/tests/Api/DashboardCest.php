<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Cache\TaskCacheKeys;
use App\Tests\Support\ApiTester;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The server-rendered dashboard: session-only access, the cached summary
 * counts, the security headers, and -- the centerpiece -- the stored-XSS
 * payloads DEMONSTRATED inert: the same strings the JSON API returns raw
 * (safe in that context) render as escaped text in HTML, because Twig
 * autoescaping is on and no |raw exists in the project.
 */
final class DashboardCest
{
    private const XSS_TITLE = "<script>alert('xss')</script>";
    private const XSS_COMMENT = '<img src=x onerror=alert(1)>';

    public function anonymousBrowsersAreRedirectedToLogin(ApiTester $I): void
    {
        // The web firewall's "401" is a 302 to the login form.
        $I->amOnPage('/dashboard');
        $I->seeInCurrentUrl('/login');

        $I->amOnPage('/dashboard/teams/1');
        $I->seeInCurrentUrl('/login');
    }

    public function anApiTokenAloneDoesNotOpenTheDashboard(ApiTester $I): void
    {
        // Two firewalls, two auth worlds: a perfectly valid Bearer token
        // means nothing outside ^/api -- the web firewall only speaks
        // sessions, so the token-bearing request is treated as anonymous.
        $token = $I->haveUserWithToken('dash-token-only@example.com');
        $I->amAuthenticatedWith($token);

        $I->stopFollowingRedirects();
        $I->sendGet('/dashboard');
        $I->seeResponseCodeIs(302);
        $I->assertStringContainsString('/login', $I->grabStringHttpHeader('Location'));
        $I->startFollowingRedirects();
    }

    public function storedXssPayloadsRenderInert(ApiTester $I): void
    {
        // Store hostile content THROUGH the API...
        $token = $I->haveUserWithToken('xss-author@example.com', 'Mallory');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('XSS team');
        $taskId = $I->haveTask($teamId, self::XSS_TITLE, ['description' => 'Totally innocent task']);
        $I->haveComment($taskId, self::XSS_COMMENT);

        // ...where JSON returns it byte-for-byte raw: safe in the
        // application/json context -- and exactly why "we're just an API"
        // stops being a defense the moment anything renders this into HTML.
        $I->sendGet("/api/tasks/{$taskId}");
        $I->seeResponseContainsJson(['title' => self::XSS_TITLE]);
        $I->seeResponseContainsJson(['body' => self::XSS_COMMENT]);

        // Switch worlds: form login -> session -> the team dashboard.
        $I->deleteHeader('Authorization');
        $this->logIn($I, 'xss-author@example.com');

        $I->amOnPage("/dashboard/teams/{$teamId}");
        $I->seeResponseCodeIsSuccessful();
        $html = $I->grabPageSource();

        // The payloads are PRESENT as escaped text (autoescaping, not filtering)...
        $I->assertStringContainsString('&lt;script&gt;alert', $html, 'title rendered as escaped text');
        $I->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html, 'comment rendered as escaped text');

        // ...and ABSENT as live markup: nothing for a browser to execute.
        $I->assertStringNotContainsString('<script>alert', $html, 'no live script tag');
        $I->assertStringNotContainsString('<img src=x', $html, 'no live img tag to fire onerror');
    }

    public function dashboardShowsCachedSummaryCountsThatWritesKeepFresh(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('dash-counts@example.com', 'Counter');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Count team');
        $I->haveTask($teamId, 'One');
        $doneId = $I->haveTask($teamId, 'Two');
        $I->sendPatch("/api/tasks/{$doneId}", ['status' => 'done']);

        $this->logIn($I, 'dash-counts@example.com');

        $I->amOnPage("/dashboard/teams/{$teamId}");
        $I->see('2 tasks');
        $I->see('1 to do');
        $I->see('1 done');

        // The render populated the summary cache entry (TTL 300s)...
        $pool = $I->grabServiceTyped('tasks.cache', CacheItemPoolInterface::class);
        $I->assertTrue($pool->getItem(TaskCacheKeys::teamSummary($teamId))->isHit());

        // ...and a task write invalidates it (same team_tasks tag as the
        // lists), so the reloaded page counts fresh, not 300s stale.
        $I->amAuthenticatedWith($token);
        $I->haveTask($teamId, 'Three');
        $I->assertFalse($pool->getItem(TaskCacheKeys::teamSummary($teamId))->isHit(), 'summary wiped by the write');

        $I->amOnPage("/dashboard/teams/{$teamId}");
        $I->see('3 tasks');
        $I->see('2 to do');
    }

    public function dashboardIndexListsTeamsWithCounts(ApiTester $I): void
    {
        $token = $I->haveUserWithToken('dash-index@example.com', 'Indexer');
        $I->amAuthenticatedWith($token);
        $teamId = $I->haveTeam('Index team');
        $I->haveTask($teamId, 'Solo task');

        $this->logIn($I, 'dash-index@example.com');

        $I->amOnPage('/dashboard');
        $I->see('Index team');
        // The team name links through to the team dashboard.
        $I->click('Index team');
        $I->seeInCurrentUrl("/dashboard/teams/{$teamId}");
        $I->see('1 task');
    }

    public function nonMembersGetA404ForForeignTeamDashboards(ApiTester $I): void
    {
        // Same policy as the API: outsiders cannot learn the team exists.
        $ownerToken = $I->haveUserWithToken('dash-owner@example.com');
        $I->amAuthenticatedWith($ownerToken);
        $teamId = $I->haveTeam('Private board');

        $I->haveUser('dash-outsider@example.com');
        $I->deleteHeader('Authorization');
        $this->logIn($I, 'dash-outsider@example.com');

        $I->amOnPage("/dashboard/teams/{$teamId}");
        $I->seeResponseCodeIs(404);
    }

    public function htmlResponsesCarrySecurityHeadersApiResponsesDoNot(ApiTester $I): void
    {
        $I->sendGet('/login');
        $I->seeHttpHeader('X-Content-Type-Options', 'nosniff');
        $I->seeHttpHeader('X-Frame-Options', 'DENY');
        $I->seeHttpHeader('Referrer-Policy', 'no-referrer');
        $csp = $I->grabStringHttpHeader('Content-Security-Policy');
        // No script may load or run from anywhere -- even markup that
        // somehow survived escaping would be dead on arrival.
        $I->assertStringContainsString("default-src 'none'", $csp);
        $I->assertStringContainsString("form-action 'self'", $csp);

        // API responses are JSON documents; the browser-document directives
        // deliberately stay off them.
        $token = $I->haveUserWithToken('dash-headers@example.com');
        $I->amAuthenticatedWith($token);
        $I->sendGet('/api/me');
        $I->seeResponseCodeIs(200);
        $I->dontSeeHttpHeader('Content-Security-Policy');
        $I->dontSeeHttpHeader('X-Frame-Options');
    }

    /** Form login through the real /login form (CSRF token and all). */
    private function logIn(ApiTester $I, string $email): void
    {
        $I->amOnPage('/login');
        $I->fillField('_username', $email);
        $I->fillField('_password', ApiTester::PASSWORD);
        $I->click('Sign in');
        $I->seeInCurrentUrl('/dashboard');
    }
}
