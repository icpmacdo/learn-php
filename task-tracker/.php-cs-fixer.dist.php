<?php

declare(strict_types=1);

/*
 * PHP-CS-Fixer config -- the style half of the quality gate (CI stage 2).
 *
 * @Symfony is the ruleset the framework itself is written against, so the
 * project reads like the code it sits on. Two deliberate deviations:
 *   - declare_strict_types (risky): every file in this project opts into
 *     strict scalar typing; the fixer enforces what the codebase already does.
 *   - non-Yoda comparisons: `$x !== null` reads naturally, and the
 *     accidental-assignment bug Yoda style guards against is caught by
 *     PHPStan anyway.
 *
 * Locally: `vendor/bin/php-cs-fixer fix` rewrites; CI runs with
 * `--dry-run --diff` so any drift fails the build instead of being patched.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->exclude('tests/Support/_generated') // Codeception-generated actor code
    ->notPath('config/bundles.php')       // Flex-managed
    ->notPath('config/reference.php')     // auto-regenerated on cache:clear
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'yoda_style' => ['equal' => false, 'identical' => false, 'less_and_greater' => false],
    ])
    ->setCacheFile(__DIR__.'/var/.php-cs-fixer.cache')
    ->setFinder($finder)
;
