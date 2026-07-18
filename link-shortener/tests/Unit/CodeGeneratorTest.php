<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\CodeGenerator;
use Codeception\Test\Unit;

final class CodeGeneratorTest extends Unit
{
    private CodeGenerator $generator;

    protected function _before(): void
    {
        $this->generator = new CodeGenerator();
    }

    public function testGeneratesExactlySevenCharacters(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(7, \strlen($this->generator->generate()));
        }
    }

    public function testUsesOnlyTheBase62Alphabet(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = $this->generator->generate();
            $this->assertMatchesRegularExpression(
                '/^[0-9A-Za-z]{7}$/',
                $code,
                sprintf('Code "%s" contains characters outside base62', $code),
            );
        }
    }

    public function testConsecutiveCallsProduceDifferentCodes(): void
    {
        // A sanity check, not a randomness proof: 20 identical draws from
        // 62^7 possibilities would mean the generator is broken.
        $codes = [];
        for ($i = 0; $i < 20; $i++) {
            $codes[] = $this->generator->generate();
        }

        $this->assertGreaterThan(1, \count(array_unique($codes)));
    }
}
