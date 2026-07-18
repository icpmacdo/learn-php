<?php

declare(strict_types=1);

// Codeception bootstrap: bring up autoloading and env vars.
//
// Force APP_ENV=test BEFORE loading .env so that .env.test is layered on top
// and the kernel boots in the test environment -- which switches Doctrine to
// the app_test database (dbname_suffix in config/packages/doctrine.yaml).

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
