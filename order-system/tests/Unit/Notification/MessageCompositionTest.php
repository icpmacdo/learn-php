<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notification;

use App\Notification\Application\Subscriber\SendOrderConfirmation;
use App\Notification\Application\Subscriber\SendPaymentReceipt;
use App\Notification\Application\Subscriber\SendShipmentNotice;
use App\Notification\Domain\EmailMessage;
use App\Notification\Domain\Mailer;
use App\Ordering\Domain\Event\OrderPaid;
use App\Ordering\Domain\Event\OrderPlaced;
use App\Ordering\Domain\Event\OrderShipped;
use Codeception\Test\Unit;

/**
 * The subscribers are plain PHP (Application layer, no framework), so message
 * composition tests need no container and no DB — just a recording Mailer.
 * Everything asserted here comes from the EVENT payload alone: Notification
 * never reads live state, which is exactly what makes it this testable.
 */
final class MessageCompositionTest extends Unit
{
    /** @var Mailer&object{sent: list<EmailMessage>} */
    private Mailer $mailer;

    protected function _before(): void
    {
        $this->mailer = new class implements Mailer {
            /** @var list<EmailMessage> */
            public array $sent = [];

            public function send(EmailMessage $message): void
            {
                $this->sent[] = $message;
            }
        };
    }

    public function testOrderConfirmationListsLinesAndTotal(): void
    {
        (new SendOrderConfirmation($this->mailer))(new OrderPlaced(
            'order-11',
            'cust-7',
            [
                ['sku' => 'WIDGET-1', 'name' => 'Widget', 'quantity' => 3, 'unitPriceMinor' => 2500, 'currency' => 'EUR'],
                ['sku' => 'GADGET-2', 'name' => 'Gadget', 'quantity' => 1, 'unitPriceMinor' => 500, 'currency' => 'EUR'],
            ],
            8000,
            'EUR',
            new \DateTimeImmutable('2026-07-18T09:30:00+00:00'),
        ));

        $this->assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        $this->assertSame(EmailMessage::TYPE_ORDER_CONFIRMATION, $message->type);
        $this->assertSame('cust-7', $message->customerId);
        $this->assertSame('order-11', $message->orderId);
        $this->assertSame('Order confirmation — order-11', $message->subject);
        $this->assertStringContainsString('- 3 x Widget (WIDGET-1) at EUR 25.00', $message->body);
        $this->assertStringContainsString('- 1 x Gadget (GADGET-2) at EUR 5.00', $message->body);
        $this->assertStringContainsString('Total: EUR 80.00', $message->body);
        $this->assertStringContainsString('2026-07-18 09:30', $message->body);
    }

    public function testPaymentReceiptCarriesAmountAndTransactionReference(): void
    {
        (new SendPaymentReceipt($this->mailer))(new OrderPaid(
            'order-11',
            'cust-7',
            'fake_tx_42',
            8000,
            'EUR',
            new \DateTimeImmutable('2026-07-18T10:00:00+00:00'),
        ));

        $this->assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        $this->assertSame(EmailMessage::TYPE_PAYMENT_RECEIPT, $message->type);
        $this->assertSame('Payment receipt — order-11', $message->subject);
        $this->assertStringContainsString('EUR 80.00', $message->body);
        $this->assertStringContainsString('fake_tx_42', $message->body);
    }

    public function testShipmentNoticeNamesTheOrderAndDate(): void
    {
        (new SendShipmentNotice($this->mailer))(new OrderShipped(
            'order-11',
            'cust-7',
            new \DateTimeImmutable('2026-07-19T08:15:00+00:00'),
        ));

        $this->assertCount(1, $this->mailer->sent);
        $message = $this->mailer->sent[0];
        $this->assertSame(EmailMessage::TYPE_SHIPMENT_NOTICE, $message->type);
        $this->assertSame('Your order has shipped — order-11', $message->subject);
        $this->assertStringContainsString('order-11', $message->body);
        $this->assertStringContainsString('2026-07-19 08:15', $message->body);
    }
}
