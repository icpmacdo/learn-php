<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Catalog\Application\Command\AddProduct;
use App\Catalog\Application\Command\AddProductHandler;
use App\Inventory\Application\Command\SetStockLevel;
use App\Inventory\Application\Command\SetStockLevelHandler;
use App\Inventory\Domain\InsufficientStock;
use App\Inventory\Domain\ReservationRepository;
use App\Inventory\Domain\ReservationStatus;
use App\Inventory\Domain\StockItemRepository;
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
use App\Ordering\Domain\CartRepository;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\ORM\EntityManagerInterface;

/**
 * THE event-seam tests: real handlers, real MySQL, real dispatcher wiring
 * from services.yaml. Checkout must move three contexts in one transaction —
 * and roll all three back together when Inventory says no.
 */
final class CheckoutFlowCest
{
    private string $sku;
    private string $customer;

    public function _before(IntegrationTester $I): void
    {
        $this->sku = 'FLOW-'.strtoupper(bin2hex(random_bytes(4)));
        $this->customer = 'flow-'.bin2hex(random_bytes(4));
    }

    public function checkoutReservesStockCreatesReservationAndEmptiesCart(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 10, cartQuantity: 3);

        $order = $this->place($I);

        $I->assertSame(OrderStatus::Placed, $order->status());
        $I->assertSame(3000, $order->total()->amountMinor);

        // Inventory reserved in the same transaction:
        $stock = $this->stock($I)->bySkuOrNull(Sku::fromString($this->sku));
        $I->assertNotNull($stock);
        $I->assertSame(10, $stock->onHand());
        $I->assertSame(3, $stock->reserved());

        // One active reservation, keyed by the order id (R1):
        $reservation = $this->reservations($I)->byOrderIdOrNull($order->id()->value);
        $I->assertNotNull($reservation);
        $I->assertSame(ReservationStatus::Active, $reservation->status());
        $I->assertCount(1, $reservation->lines());
        $I->assertSame(3, $reservation->lines()[0]->quantity()->value);

        // The cart was consumed:
        $cart = $this->carts($I)->byCustomerOrNull(CustomerId::fromString($this->customer));
        $I->assertNotNull($cart);
        $I->assertTrue($cart->isEmpty());
    }

    public function multiLineCheckoutReservesEveryLineInOneReservation(IntegrationTester $I): void
    {
        $skuA = $this->sku.'-A';
        $skuB = $this->sku.'-B';
        $this->addProductWithSku($I, $skuA);
        $this->addProductWithSku($I, $skuB);
        $this->setStock($I, $skuA, 10);
        $this->setStock($I, $skuB, 5);
        $this->putCartLineForSku($I, $skuA, 2);
        $this->putCartLineForSku($I, $skuB, 3);

        $order = $this->place($I);

        $I->assertSame(OrderStatus::Placed, $order->status());
        $I->assertSame(5000, $order->total()->amountMinor); // 2x1000 + 3x1000

        // BOTH lines reserved — the subscriber's per-line loop is only ever
        // exercised past its first iteration by a multi-line cart:
        $stockA = $this->stock($I)->bySkuOrNull(Sku::fromString($skuA));
        $stockB = $this->stock($I)->bySkuOrNull(Sku::fromString($skuB));
        $I->assertNotNull($stockA);
        $I->assertNotNull($stockB);
        $I->assertSame(2, $stockA->reserved());
        $I->assertSame(3, $stockB->reserved());

        // One reservation, two lines (R1):
        $reservation = $this->reservations($I)->byOrderIdOrNull($order->id()->value);
        $I->assertNotNull($reservation);
        $quantitiesBySku = [];
        foreach ($reservation->lines() as $line) {
            $quantitiesBySku[$line->sku()->value] = $line->quantity()->value;
        }
        $I->assertSame([$skuA => 2, $skuB => 3], $quantitiesBySku);
    }

    public function insufficientStockOnALaterLineRollsBackEarlierReservations(IntegrationTester $I): void
    {
        // Regression: with a single SKU, reserve() throws on the FIRST line
        // before any write exists, so only a >= 2-line cart can catch a
        // partial reservation surviving the rollback (e.g. a per-line flush
        // regression in the subscriber or the TransactionBoundary).
        $skuA = $this->sku.'-A'; // reserves fine (cart lines iterate sku-ordered)
        $skuB = $this->sku.'-B'; // then throws InsufficientStock
        $this->addProductWithSku($I, $skuA);
        $this->addProductWithSku($I, $skuB);
        $this->setStock($I, $skuA, 10);
        $this->setStock($I, $skuB, 1);
        $this->putCartLineForSku($I, $skuA, 2);
        $this->putCartLineForSku($I, $skuB, 5);

        $I->expectThrowable(InsufficientStock::class, function () use ($I): void {
            $this->place($I);
        });

        $this->em($I)->clear();

        // Sku A's reservation was WRITTEN before B threw — it must be gone:
        $stockA = $this->stock($I)->bySkuOrNull(Sku::fromString($skuA));
        $stockB = $this->stock($I)->bySkuOrNull(Sku::fromString($skuB));
        $I->assertNotNull($stockA);
        $I->assertNotNull($stockB);
        $I->assertSame(0, $stockA->reserved());
        $I->assertSame(0, $stockB->reserved());

        // No orphaned reservation line either:
        $connection = $this->em($I)->getConnection();
        $lineCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM inventory_reservation_line WHERE sku IN (:a, :b)',
            ['a' => $skuA, 'b' => $skuB],
        );
        $I->assertSame(0, (int) $lineCount);

        // Cart intact, no orphaned order:
        $cart = $this->carts($I)->byCustomerOrNull(CustomerId::fromString($this->customer));
        $I->assertNotNull($cart);
        $I->assertSame([$skuA => 2, $skuB => 5], $cart->lines());
        $orderCount = $connection->fetchOne(
            'SELECT COUNT(*) FROM ordering_order WHERE customer_id = :c',
            ['c' => $this->customer],
        );
        $I->assertSame(0, (int) $orderCount);
    }

    public function insufficientStockRollsTheWholeCheckoutBack(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 2, cartQuantity: 5);

        $I->expectThrowable(InsufficientStock::class, function () use ($I): void {
            $this->place($I);
        });

        // The savepoint rollback erased every partial write; drop the stale
        // identity map before checking the database's truth.
        $this->em($I)->clear();

        $stock = $this->stock($I)->bySkuOrNull(Sku::fromString($this->sku));
        $I->assertNotNull($stock);
        $I->assertSame(0, $stock->reserved()); // no partial reservation

        $cart = $this->carts($I)->byCustomerOrNull(CustomerId::fromString($this->customer));
        $I->assertNotNull($cart);
        $I->assertSame([$this->sku => 5], $cart->lines()); // cart intact

        $orderCount = $I->grabService(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM ordering_order WHERE customer_id = :c',
            ['c' => $this->customer],
        );
        $I->assertSame(0, (int) $orderCount); // no orphaned order row
    }

    public function aSkuWithoutAnyStockRecordIsInsufficient(IntegrationTester $I): void
    {
        // Product exists in the catalog, warehouse never heard of it.
        $this->addProduct($I);
        $this->putCartLine($I, 1);

        $I->expectThrowable(InsufficientStock::class, function () use ($I): void {
            $this->place($I);
        });
    }

    public function cancellingAPlacedOrderReleasesStockAndFinalizesReservation(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 10, cartQuantity: 4);
        $order = $this->place($I);

        $I->grabService(CancelOrderHandler::class)->handle(
            new CancelOrder($this->customer, $order->id()->value),
        );

        $stock = $this->stock($I)->bySkuOrNull(Sku::fromString($this->sku));
        $I->assertNotNull($stock);
        $I->assertSame(10, $stock->onHand());
        $I->assertSame(0, $stock->reserved()); // back on the shelf

        $reservation = $this->reservations($I)->byOrderIdOrNull($order->id()->value);
        $I->assertNotNull($reservation);
        $I->assertSame(ReservationStatus::Released, $reservation->status());
    }

    public function shippingAPaidOrderCommitsStockOutOfTheWarehouse(IntegrationTester $I): void
    {
        $this->seed($I, onHand: 10, cartQuantity: 4);
        $order = $this->place($I);

        $I->grabService(PayOrderHandler::class)->handle(
            new PayOrder($this->customer, $order->id()->value, 'tok_success'),
        );
        $I->grabService(ShipOrderHandler::class)->handle(new ShipOrder($order->id()->value));

        $stock = $this->stock($I)->bySkuOrNull(Sku::fromString($this->sku));
        $I->assertNotNull($stock);
        $I->assertSame(6, $stock->onHand());   // stock left the building
        $I->assertSame(0, $stock->reserved()); // S1 stays honest end-to-end

        $reservation = $this->reservations($I)->byOrderIdOrNull($order->id()->value);
        $I->assertNotNull($reservation);
        $I->assertSame(ReservationStatus::Committed, $reservation->status());
    }

    // ------------------------------------------------------------- plumbing

    private function seed(IntegrationTester $I, int $onHand, int $cartQuantity): void
    {
        $this->addProduct($I);
        $this->setStock($I, $this->sku, $onHand);
        $this->putCartLine($I, $cartQuantity);
    }

    private function addProduct(IntegrationTester $I): void
    {
        $this->addProductWithSku($I, $this->sku);
    }

    private function addProductWithSku(IntegrationTester $I, string $sku): void
    {
        $I->grabService(AddProductHandler::class)->handle(
            new AddProduct($sku, 'Flow Widget', null, 1000, 'EUR'),
        );
    }

    private function setStock(IntegrationTester $I, string $sku, int $onHand): void
    {
        $I->grabService(SetStockLevelHandler::class)->handle(new SetStockLevel($sku, $onHand));
    }

    private function putCartLine(IntegrationTester $I, int $quantity): void
    {
        $this->putCartLineForSku($I, $this->sku, $quantity);
    }

    private function putCartLineForSku(IntegrationTester $I, string $sku, int $quantity): void
    {
        $I->grabService(PutCartLineHandler::class)->handle(
            new PutCartLine($this->customer, $sku, $quantity),
        );
    }

    private function place(IntegrationTester $I): Order
    {
        return $I->grabService(PlaceOrderHandler::class)->handle(new PlaceOrder($this->customer));
    }

    private function stock(IntegrationTester $I): StockItemRepository
    {
        return $I->grabService(StockItemRepository::class);
    }

    private function reservations(IntegrationTester $I): ReservationRepository
    {
        return $I->grabService(ReservationRepository::class);
    }

    private function carts(IntegrationTester $I): CartRepository
    {
        return $I->grabService(CartRepository::class);
    }

    private function em(IntegrationTester $I): EntityManagerInterface
    {
        return $I->grabService(EntityManagerInterface::class);
    }
}
