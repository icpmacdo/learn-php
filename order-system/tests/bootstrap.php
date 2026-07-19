<?php

declare(strict_types=1);

// Flex-generated PHPUnit bootstrap. Codeception uses tests/_bootstrap.php
// instead (which forces APP_ENV=test); this file stays for `bin/phpunit`
// compatibility only.

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
