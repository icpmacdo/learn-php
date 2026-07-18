<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\Support\ApiTester;

/**
 * The browser world: form login -> session -> dashboard. (API tokens play no
 * part here; the web firewall only speaks sessions.).
 */
final class LoginCest
{
    public function loginPageRenders(ApiTester $I): void
    {
        $I->amOnPage('/login');

        $I->seeResponseCodeIsSuccessful();
        $I->see('Log in');
        $I->seeElement('input', ['name' => '_username']);
    }

    public function anonymousDashboardRedirectsToLogin(ApiTester $I): void
    {
        // The web firewall's "401" is a 302 to the login form.
        $I->amOnPage('/dashboard');

        $I->seeInCurrentUrl('/login');
    }

    public function validCredentialsLogInAndReachTheDashboard(ApiTester $I): void
    {
        $I->haveUser('webby@example.com', 'Webby');

        $I->amOnPage('/login');
        $I->fillField('_username', 'webby@example.com');
        $I->fillField('_password', ApiTester::PASSWORD);
        $I->click('Sign in');

        $I->seeInCurrentUrl('/dashboard');
        $I->see('Webby');
    }

    public function wrongPasswordBouncesBackWithAnError(ApiTester $I): void
    {
        $I->haveUser('bouncer@example.com');

        $I->amOnPage('/login');
        $I->fillField('_username', 'bouncer@example.com');
        $I->fillField('_password', 'wrong-password');
        $I->click('Sign in');

        $I->seeInCurrentUrl('/login');
        $I->see('Invalid credentials.');
    }

    public function logoutEndsTheSession(ApiTester $I): void
    {
        $I->haveUser('leaver@example.com', 'Leaver');
        $I->amOnPage('/login');
        $I->fillField('_username', 'leaver@example.com');
        $I->fillField('_password', ApiTester::PASSWORD);
        $I->click('Sign in');
        $I->seeInCurrentUrl('/dashboard');

        $I->click('Log out');

        $I->seeInCurrentUrl('/login');
        $I->amOnPage('/dashboard');
        $I->seeInCurrentUrl('/login');
    }
}
