<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Catalog\Application\Command\AddProduct;
use App\Catalog\Application\Command\AddProductHandler;
use App\Inventory\Application\Command\SetStockLevel;
use App\Inventory\Application\Command\SetStockLevelHandler;
use App\Inventory\Domain\InsufficientStock;
use App\Ordering\Application\Command\CancelOrder;
use App\Ordering\Application\Command\CancelOrderHandler;
use App\Ordering\Application\Command\PayOrder;
use App\Ordering\Application\Command\PayOrderHandler;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Command\PlaceOrderHandler;
use App\Ordering\Application\Command\PutCartLine;
use App\Ordering\Application\Command\PutCartLineHandler;
use App\Ordering\Application\Command\ShipOrder;
use App\Ordering\Application\Command\ShipOrderHandler;
use App\Ordering\Domain\Order;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The Notification half of the event seam, against real MySQL: each order
 * event leaves exactly one notification_log row with the right type and
 * content — and a rolled-back checkout leaves none (the confirmation rides
 * the checkout transaction).
 */
final class NotificationLogCest
{
    private string $sku;
    private string $customer;

    public function _before(IntegrationTester $I): void
    {
        $this->sku = 'NOTE-'.strtoupper(bin2hex(random_bytes(4)));
        $this->customer = 'note-'.bin2hex(random_bytes(4));
    }

    public function placingAnOrderLogsAConfirmationWithLinesAndTotal(IntegrationTester $I): void
    {
        $order = $this->placeOrder($I, onHand: 10, cartQuantity: 3);

        $rows = $this->rowsFor($I, $order->id()->value);
        $I->assertCount(1, $rows);
        $I->assertSame('order_confirmation', $rows[0]['type']);
        $I->assertSame($this->customer, $rows[0]['customer_id']);
        $I->assertSame('Order confirmation — '.$order->id()->value, $rows[0]['subject']);
        $I->assertStringContainsString('3 x Notified Widget ('.$this->sku.') at EUR 10.00', $rows[0]['body']);
        $I->assertStringContainsString('Total: EUR 30.00', $rows[0]['body']);
    }

    public function payingLogsAReceiptWithTheTransactionReference(IntegrationTester $I): void
    {
        $order = $this->placeOrder($I, onHand: 10, cartQuantity: 2);
        $I->grabService(PayOrderHandler::class)->handle(
            new PayOrder($this->customer, $order->id()->value, 'tok_success'),
        );

        $rows = $this->rowsFor($I, $order->id()->value);
        $I->assertCount(2, $rows);
        $I->assertSame('payment_receipt', $rows[1]['type']);
        $I->assertStringContainsString('EUR 20.00', $rows[1]['body']);
        $I->assertStringContainsString($order->transactionId() ?? '', $rows[1]['body']);
    }

    public function shippingLogsAShipmentNotice(IntegrationTester $I): void
    {
        $order = $this->placeOrder($I, onHand: 10, cartQuantity: 1);
        $I->grabService(PayOrderHandler::class)->handle(
            new PayOrder($this->customer, $order->id()->value, 'tok_success'),
        );
        $I->grabService(ShipOrderHandler::class)->handle(new ShipOrder($order->id()->value));

        $types = array_column($this->rowsFor($I, $order->id()->value), 'type');
        $I->assertSame(['order_confirmation', 'payment_receipt', 'shipment_notice'], $types);
    }

    public function cancellationSendsNothing(IntegrationTester $I): void
    {
        // The PRD's consumer table has no notification on OrderCancelled —
        // proving a NON-subscription is part of the seam's contract too.
        $order = $this->placeOrder($I, onHand: 10, cartQuantity: 1);
        $I->grabService(CancelOrderHandler::class)->handle(
            new CancelOrder($this->customer, $order->id()->value),
        );

        $types = array_column($this->rowsFor($I, $order->id()->value), 'type');
        $I->assertSame(['order_confirmation'], $types);
    }

    public function aRolledBackCheckoutLeavesNoConfirmationRow(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 1, cartQuantity: 5);

        $I->expectThrowable(InsufficientStock::class, function () use ($I): void {
            $I->grabService(PlaceOrderHandler::class)->handle(new PlaceOrder($this->customer));
        });

        $count = $this->connection($I)->fetchOne(
            'SELECT COUNT(*) FROM notification_log WHERE customer_id = :c',
            ['c' => $this->customer],
        );
        $I->assertSame(0, (int) $count);
    }

    // ------------------------------------------------------------- plumbing

    private function seed(IntegrationTester $I, int $onHand, int $cartQuantity): void
    {
        $I->grabService(AddProductHandler::class)->handle(
            new AddProduct($this->sku, 'Notified Widget', null, 1000, 'EUR'),
        );
        $I->grabService(SetStockLevelHandler::class)->handle(new SetStockLevel($this->sku, $onHand));
        $I->grabService(PutCartLineHandler::class)->handle(
            new PutCartLine($this->customer, $this->sku, $cartQuantity),
        );
    }

    private function placeOrder(IntegrationTester $I, int $onHand, int $cartQuantity): Order
    {
        $this->seed($I, $onHand, $cartQuantity);

        return $I->grabService(PlaceOrderHandler::class)->handle(new PlaceOrder($this->customer));
    }

    /** @return list<array{type: string, customer_id: string, order_id: string, subject: string, body: string}> */
    private function rowsFor(IntegrationTester $I, string $orderId): array
    {
        /** @var list<array{type: string, customer_id: string, order_id: string, subject: string, body: string}> $rows */
        $rows = $this->connection($I)->fetchAllAssociative(
            'SELECT type, customer_id, order_id, subject, body FROM notification_log WHERE order_id = :o ORDER BY id',
            ['o' => $orderId],
        );

        return $rows;
    }

    private function connection(IntegrationTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
