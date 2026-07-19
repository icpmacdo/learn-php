<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ordering;

use App\Ordering\Domain\Port\PaymentMethodToken;
use Codeception\Test\Unit;

/**
 * The token VO validates SHAPE only (1-64 non-whitespace chars, outer
 * whitespace trimmed); whether a token is chargeable is the gateway's call.
 * The HTTP edge (PayOrderRequest) mirrors these rules so a bad shape is a
 * 422 at the DTO, never an escaped \InvalidArgumentException (500).
 */
final class PaymentMethodTokenTest extends Unit
{
    public function testAcceptsATypicalToken(): void
    {
        $this->assertSame('tok_success', PaymentMethodToken::fromString('tok_success')->value);
    }

    public function testTrimsOuterWhitespace(): void
    {
        $this->assertSame('tok_visa', PaymentMethodToken::fromString("  tok_visa\n")->value);
    }

    public function testAcceptsExactly64Characters(): void
    {
        $token = str_repeat('a', 64);
        $this->assertSame($token, PaymentMethodToken::fromString($token)->value);
    }

    public function testRejectsEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentMethodToken::fromString('');
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentMethodToken::fromString('   ');
    }

    public function testRejectsInternalWhitespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentMethodToken::fromString('tok abc');
    }

    public function testRejectsOver64Characters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PaymentMethodToken::fromString(str_repeat('a', 65));
    }
}
