<?php

declare(strict_types=1);

/*
 * Entity-manager loader for phpstan-doctrine.
 *
 * PHPStan boots the real kernel to read Doctrine's mapping metadata, so it
 * knows the XML mappings, the custom SKU type and the Money embeddable.
 * Metadata only: no database connection is ever opened, so this works in CI
 * where no MySQL is running during static analysis.
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel('dev', false);
$kernel->boot();

$doctrine = $kernel->getContainer()->get('doctrine');
\assert($doctrine instanceof Doctrine\Persistence\ManagerRegistry);

return $doctrine->getManager();
