<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Command\AddProduct;
use App\Catalog\Application\Command\AddProductHandler;
use App\Catalog\Application\Command\UpdateProduct;
use App\Catalog\Application\Command\UpdateProductHandler;
use App\Catalog\Infrastructure\Persistence\ProductListQuery;
use App\Inventory\Application\Command\SetStockLevel;
use App\Inventory\Application\Command\SetStockLevelHandler;
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
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * read_product_list stays consistent through every flow that touches it:
 * two contexts' events feed one row (Catalog: name/price/active — Inventory:
 * available), in whatever order the commands arrive.
 */
final class ProductListProjectionCest
{
    private string $sku;
    private string $customer;

    public function _before(IntegrationTester $I): void
    {
        $this->sku = 'PROJ-'.strtoupper(bin2hex(random_bytes(4)));
        $this->customer = 'proj-'.bin2hex(random_bytes(4));
    }

    public function addingAProductProjectsAMerchandisingRow(IntegrationTester $I): void
    {
        $this->addProduct($I, priceMinor: 1999);

        $row = $this->row($I);
        $I->assertSame('Projected Widget', $row['name']);
        $I->assertSame(1999, (int) $row['price_minor']);
        $I->assertSame('EUR', $row['currency']);
        $I->assertSame(1, (int) $row['active']);
        $I->assertSame(0, (int) $row['available']);
        $I->assertSame(1, (int) $row['product_known']);
    }

    public function priceChangeAndDeactivationUpdateTheRow(IntegrationTester $I): void
    {
        $this->addProduct($I, priceMinor: 1000);

        $I->grabService(UpdateProductHandler::class)->handle(
            new UpdateProduct($this->sku, null, 2500, 'EUR', false),
        );

        $row = $this->row($I);
        $I->assertSame(2500, (int) $row['price_minor']);
        $I->assertSame(0, (int) $row['active']);

        // Inactive products still appear in the listing (flagged inactive):
        $I->assertNotNull($this->listedRow($I));
    }

    public function renameUpdatesTheProjectedName(IntegrationTester $I): void
    {
        // Regression: rename() once recorded no event, so the projection's
        // name column drifted from the write model permanently (ProductAdded
        // writes it only once per SKU).
        $this->addProduct($I, priceMinor: 1000);

        $I->grabService(UpdateProductHandler::class)->handle(
            new UpdateProduct($this->sku, 'Renamed Widget', null, null, null),
        );

        $row = $this->row($I);
        $I->assertSame('Renamed Widget', $row['name']);
        // The other merchandising columns are untouched by a rename:
        $I->assertSame(1000, (int) $row['price_minor']);
        $I->assertSame(1, (int) $row['active']);
    }

    public function stockEventsDriveAvailabilityThroughTheWholeLifecycle(IntegrationTester $I): void
    {
        $this->addProduct($I, priceMinor: 1000);
        $this->setStock($I, 10);
        $I->assertSame(10, $this->available($I)); // StockReceived

        $this->putCartLine($I, 4);
        $orderId = $this->place($I);
        $I->assertSame(6, $this->available($I)); // StockReserved

        $I->grabService(PayOrderHandler::class)->handle(new PayOrder($this->customer, $orderId, 'tok_success'));
        $I->grabService(ShipOrderHandler::class)->handle(new ShipOrder($orderId));
        // StockCommitted: onHand 10->6, reserved 4->0 — available unchanged.
        $I->assertSame(6, $this->available($I));
    }

    public function cancellationRestoresProjectedAvailability(IntegrationTester $I): void
    {
        $this->addProduct($I, priceMinor: 1000);
        $this->setStock($I, 8);
        $this->putCartLine($I, 5);
        $orderId = $this->place($I);
        $I->assertSame(3, $this->available($I));

        $I->grabService(CancelOrderHandler::class)->handle(new CancelOrder($this->customer, $orderId));
        $I->assertSame(8, $this->available($I)); // StockReleased
    }

    public function stockBeforeProductSurvivesInAHiddenRowUntilTheProductIsAdded(IntegrationTester $I): void
    {
        // The warehouse may stock a SKU the catalog has never announced:
        // the availability lands in a product_known=0 row that the listing
        // hides...
        $this->setStock($I, 7);
        $row = $this->row($I);
        $I->assertSame(7, (int) $row['available']);
        $I->assertSame(0, (int) $row['product_known']);
        $I->assertNull($this->listedRow($I));

        // ...and ProductAdded flips the SAME row visible, availability intact.
        $this->addProduct($I, priceMinor: 1500);
        $listed = $this->listedRow($I);
        $I->assertNotNull($listed);
        $I->assertSame(7, $listed['available']);
        $I->assertSame(1500, $listed['price']['amountMinor']);
    }

    // ------------------------------------------------------------- plumbing

    private function addProduct(IntegrationTester $I, int $priceMinor): void
    {
        $I->grabService(AddProductHandler::class)->handle(
            new AddProduct($this->sku, 'Projected Widget', null, $priceMinor, 'EUR'),
        );
    }

    private function setStock(IntegrationTester $I, int $onHand): void
    {
        $I->grabService(SetStockLevelHandler::class)->handle(new SetStockLevel($this->sku, $onHand));
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

    /** @return array<string, mixed> */
    private function row(IntegrationTester $I): array
    {
        $row = $this->connection($I)->fetchAssociative(
            'SELECT * FROM read_product_list WHERE sku = :sku',
            ['sku' => $this->sku],
        );
        $I->assertNotFalse($row, 'expected a read_product_list row for '.$this->sku);

        /* @var array<string, mixed> $row */
        return $row;
    }

    private function available(IntegrationTester $I): int
    {
        return (int) $this->row($I)['available'];
    }

    /**
     * The row as the API's listing query sees it (or null if hidden).
     *
     * @return array{sku: string, name: string, price: array{amountMinor: int, currency: string}, active: bool, available: int}|null
     */
    private function listedRow(IntegrationTester $I): ?array
    {
        // The listing is sku-ordered; page through until we pass our SKU.
        $query = $I->grabService(ProductListQuery::class);
        for ($page = 1; $page <= 100; ++$page) {
            $result = $query->list($page, 100);
            foreach ($result['products'] as $product) {
                if ($product['sku'] === $this->sku) {
                    return $product;
                }
            }
            if ($page * 100 >= $result['total']) {
                return null;
            }
        }

        return null;
    }

    private function connection(IntegrationTester $I): Connection
    {
        return $I->grabService(EntityManagerInterface::class)->getConnection();
    }
}
