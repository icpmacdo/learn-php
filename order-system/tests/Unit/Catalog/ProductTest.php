<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Event\ProductAdded;
use App\Catalog\Domain\Event\ProductDeactivated;
use App\Catalog\Domain\Event\ProductPriceChanged;
use App\Catalog\Domain\Event\ProductReactivated;
use App\Catalog\Domain\Event\ProductRenamed;
use App\Catalog\Domain\InvalidProductPrice;
use App\Catalog\Domain\Product;
use App\Catalog\Domain\ProductName;
use App\Shared\Domain\Money;
use App\Shared\Domain\Sku;
use Codeception\Test\Unit;

/**
 * Product aggregate: invariant P1 (price > 0) and event recording. Pure PHP,
 * no container, no DB — the aggregate is testable precisely because it
 * imports no framework code.
 */
final class ProductTest extends Unit
{
    private \DateTimeImmutable $now;

    protected function _before(): void
    {
        $this->now = new \DateTimeImmutable('2026-07-18T12:00:00+00:00');
    }

    public function testAddCreatesActiveProductAndRecordsProductAdded(): void
    {
        $product = $this->product();

        $this->assertSame('WIDGET-1', $product->sku()->value);
        $this->assertSame('Widget', $product->name());
        $this->assertTrue($product->isActive());
        $this->assertTrue($product->price()->equals(Money::of(1999, 'EUR')));

        $events = $product->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(ProductAdded::class, $event);
        $this->assertSame('WIDGET-1', $event->sku->value);
        $this->assertSame('Widget', $event->name);
        $this->assertSame(1999, $event->price->amountMinor);
    }

    public function testReleaseEventsDrainsTheBuffer(): void
    {
        $product = $this->product();

        $this->assertCount(1, $product->releaseEvents());
        $this->assertCount(0, $product->releaseEvents());
    }

    public function testZeroPriceIsRejected(): void
    {
        $this->expectException(InvalidProductPrice::class);
        Product::add(
            Sku::fromString('WIDGET-1'),
            ProductName::fromString('Widget'),
            null,
            Money::of(0, 'EUR'),
            $this->now,
        );
    }

    public function testChangePriceRecordsEvent(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        $product->changePrice(Money::of(2499, 'EUR'), $this->now);

        $this->assertTrue($product->price()->equals(Money::of(2499, 'EUR')));
        $events = $product->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(ProductPriceChanged::class, $event);
        $this->assertSame(2499, $event->price->amountMinor);
    }

    public function testChangePriceToSameValueRecordsNothing(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        $product->changePrice(Money::of(1999, 'EUR'), $this->now);

        $this->assertCount(0, $product->releaseEvents());
    }

    public function testChangePriceToZeroIsRejected(): void
    {
        $product = $this->product();

        $this->expectException(InvalidProductPrice::class);
        $product->changePrice(Money::of(0, 'EUR'), $this->now);
    }

    public function testDeactivateAndReactivateRecordEventsOnceEach(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        $product->deactivate($this->now);
        $product->deactivate($this->now); // idempotent: no second event

        $this->assertFalse($product->isActive());
        $events = $product->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ProductDeactivated::class, $events[0]);

        $product->reactivate($this->now);
        $product->reactivate($this->now);

        $this->assertTrue($product->isActive());
        $events = $product->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ProductReactivated::class, $events[0]);
    }

    public function testRenameRecordsEvent(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        $product->rename(ProductName::fromString('Improved Widget'), $this->now);

        $this->assertSame('Improved Widget', $product->name());
        // Regression: rename() once recorded NO event, so the projected
        // read_product_list name column drifted from the write model forever.
        $events = $product->releaseEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(ProductRenamed::class, $event);
        $this->assertSame('WIDGET-1', $event->sku->value);
        $this->assertSame('Improved Widget', $event->name);
    }

    public function testRenameToSameNameRecordsNothing(): void
    {
        $product = $this->product();
        $product->releaseEvents();

        $product->rename(ProductName::fromString('Widget'), $this->now);

        $this->assertCount(0, $product->releaseEvents());
    }

    public function testProductNameGuards(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductName::fromString('   ');
    }

    public function testProductNameMaxLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProductName::fromString(str_repeat('x', 256));
    }

    private function product(): Product
    {
        return Product::add(
            Sku::fromString('WIDGET-1'),
            ProductName::fromString('Widget'),
            'A fine widget.',
            Money::of(1999, 'EUR'),
            $this->now,
        );
    }
}
