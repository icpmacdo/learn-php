<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\MoneyText;
use Codeception\Test\Unit;

final class EmailMessageTest extends Unit
{
    public function testHoldsItsParts(): void
    {
        $message = new EmailMessage(
            EmailMessage::TYPE_ORDER_CONFIRMATION,
            'cust-1',
            'order-1',
            'Subject',
            'Body',
        );

        $this->assertSame('order_confirmation', $message->type);
        $this->assertSame('cust-1', $message->customerId);
        $this->assertSame('order-1', $message->orderId);
    }

    public function testUnknownTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailMessage('carrier_pigeon', 'cust-1', 'order-1', 'Subject', 'Body');
    }

    /** @dataProvider blankParts */
    public function testBlankSubjectOrBodyIsRejected(string $subject, string $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new EmailMessage(EmailMessage::TYPE_SHIPMENT_NOTICE, 'cust-1', 'order-1', $subject, $body);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blankParts(): iterable
    {
        yield 'blank subject' => ['   ', 'Body'];
        yield 'blank body' => ['Subject', ''];
    }

    /** @dataProvider amounts */
    public function testMoneyTextFormatsMinorUnitsWithoutFloats(int $minor, string $expected): void
    {
        $this->assertSame($expected, MoneyText::format($minor, 'EUR'));
    }

    /** @return iterable<string, array{int, string}> */
    public static function amounts(): iterable
    {
        yield 'round' => [7500, 'EUR 75.00'];
        yield 'cents' => [1999, 'EUR 19.99'];
        yield 'sub-euro' => [5, 'EUR 0.05'];
        yield 'zero' => [0, 'EUR 0.00'];
        yield 'large (would lose precision as a float)' => [922337203685477580, 'EUR 9223372036854775.80'];
    }
}
