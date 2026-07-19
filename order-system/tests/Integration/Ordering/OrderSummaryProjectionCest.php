<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

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
use App\Ordering\Infrastructure\Persistence\OrderHistoryQuery;
use App\Tests\Support\IntegrationTester;

/**
 * read_order_summary follows the write model through every transition —
 * same transaction, so the history can never disagree with the aggregate —
 * and a rolled-back checkout projects nothing.
 */
final class OrderSummaryProjectionCest
{
    private string $sku;
    private string $customer;

    public function _before(IntegrationTester $I): void
    {
        $this->sku = 'SUMM-'.strtoupper(bin2hex(random_bytes(4)));
        $this->customer = 'summ-'.bin2hex(random_bytes(4));
    }

    public function placementProjectsASummaryRow(IntegrationTester $I): void
    {
        $orderId = $this->placeOrder($I, cartQuantity: 3, priceMinor: 2500);

        $orders = $this->history($I);
        $I->assertCount(1, $orders);
        $I->assertSame($orderId, $orders[0]['id']);
        $I->assertSame('placed', $orders[0]['status']);
        $I->assertSame(7500, $orders[0]['total']['amountMinor']);
        $I->assertSame('EUR', $orders[0]['total']['currency']);
        $I->assertSame(1, $orders[0]['lineCount']);
    }

    public function statusFollowsPayShipAndCancel(IntegrationTester $I): void
    {
        $orderId = $this->placeOrder($I, cartQuantity: 1, priceMinor: 1000);

        $I->grabService(PayOrderHandler::class)->handle(new PayOrder($this->customer, $orderId, 'tok_success'));
        $I->assertSame('paid', $this->history($I)[0]['status']);

        $I->grabService(ShipOrderHandler::class)->handle(new ShipOrder($orderId));
        $I->assertSame('shipped', $this->history($I)[0]['status']);

        // A second order, cancelled — history shows both, newest first:
        $this->putCartLine($I, 2);
        $secondId = $this->place($I);
        $I->grabService(CancelOrderHandler::class)->handle(new CancelOrder($this->customer, $secondId));

        $orders = $this->history($I);
        $I->assertCount(2, $orders);
        $I->assertSame($secondId, $orders[0]['id']);
        $I->assertSame('cancelled', $orders[0]['status']);
        $I->assertSame($orderId, $orders[1]['id']);
    }

    public function aRolledBackCheckoutProjectsNoSummaryRow(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 1, priceMinor: 1000);
        $this->putCartLine($I, 5);

        $I->expectThrowable(InsufficientStock::class, function () use ($I): void {
            $this->place($I);
        });

        $I->assertSame([], $this->history($I));
    }

    // ------------------------------------------------------------- plumbing

    private function seed(IntegrationTester $I, int $onHand, int $priceMinor): void
    {
        $I->grabService(AddProductHandler::class)->handle(
            new AddProduct($this->sku, 'Summary Widget', null, $priceMinor, 'EUR'),
        );
        $I->grabService(SetStockLevelHandler::class)->handle(new SetStockLevel($this->sku, $onHand));
    }

    private function placeOrder(IntegrationTester $I, int $cartQuantity, int $priceMinor): string
    {
        $this->seed($I, onHand: 20, priceMinor: $priceMinor);
        $this->putCartLine($I, $cartQuantity);

        return $this->place($I);
    }

    private function putCartLine(IntegrationTester $I, int $quantity): void
    {
        $I->grabService(PutCartLineHandler::class)->handle(
            new PutCartLine($this->customer, $this->sku, $quantity),
        );
    }

    private function place(IntegrationTester $I): string
    {
        return $I->grabService(PlaceOrderHandler::class)
            ->handle(new PlaceOrder($this->customer))->id()->value;
    }

    /** @return list<array{id: string, status: string, total: array{amountMinor: int, currency: string}, lineCount: int, placedAt: string, updatedAt: string}> */
    private function history(IntegrationTester $I): array
    {
        return $I->grabService(OrderHistoryQuery::class)->forCustomer($this->customer);
    }
}
