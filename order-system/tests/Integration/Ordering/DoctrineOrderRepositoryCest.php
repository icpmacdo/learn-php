<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Ordering\Domain\NewOrderLine;
use App\Ordering\Domain\Order;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderNotFound;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\OrderStatus;
use App\Shared\Domain\CustomerId;
use App\Shared\Domain\Money;
use App\Shared\Domain\Quantity;
use App\Shared\Domain\Sku;
use App\Tests\Support\IntegrationTester;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The riskiest mapping in the project round-trips here: custom id type
 * (order_id), enum-typed status, the Money embeddable inside child entities,
 * and the plain-array-to-PersistentCollection lines association.
 */
final class DoctrineOrderRepositoryCest
{
    public function roundTripsAPlacedOrderWithLines(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $order = $this->placedOrder();
        $id = $order->id();

        $repo->add($order);
        $this->em($I)->clear(); // force a real DB read, not identity-map echo

        $reloaded = $repo->get($id);

        $I->assertTrue($reloaded->id()->equals($id));
        $I->assertSame(OrderStatus::Placed, $reloaded->status());
        $I->assertSame('cust-int-1', $reloaded->customerId()->value);
        $I->assertSame('EUR', $reloaded->currency());
        $I->assertNull($reloaded->paidAt());

        $lines = $reloaded->lines();
        $I->assertCount(2, $lines);
        $I->assertSame('IT-WIDGET', $lines[0]->sku()->value);
        $I->assertSame('Widget', $lines[0]->name());
        $I->assertTrue($lines[0]->unitPrice()->equals(Money::of(1999, 'EUR')));
        $I->assertSame(2, $lines[0]->quantity()->value);
        $I->assertTrue($reloaded->total()->equals(Money::of(5498, 'EUR'))); // O2 survives hydration
    }

    public function persistsEveryTransition(IntegrationTester $I): void
    {
        $repo = $this->repo($I);
        $order = $this->placedOrder();
        $id = $order->id();
        $repo->add($order);

        $order->pay('fake_tx-int', new \DateTimeImmutable('2026-07-18T13:00:00+00:00'));
        $repo->save($order);
        $this->em($I)->clear();

        $reloaded = $repo->get($id);
        $I->assertSame(OrderStatus::Paid, $reloaded->status());
        $I->assertSame('fake_tx-int', $reloaded->transactionId());
        $I->assertSame('2026-07-18T13:00:00+00:00', $reloaded->paidAt()?->format(\DateTimeInterface::ATOM));

        $reloaded->ship(new \DateTimeImmutable('2026-07-18T14:00:00+00:00'));
        $repo->save($reloaded);
        $this->em($I)->clear();

        $shipped = $repo->get($id);
        $I->assertSame(OrderStatus::Shipped, $shipped->status());
        $I->assertNotNull($shipped->shippedAt());
    }

    public function getUnknownIdThrowsOrderNotFound(IntegrationTester $I): void
    {
        $I->expectThrowable(OrderNotFound::class, function () use ($I): void {
            $this->repo($I)->get(OrderId::generate());
        });
    }

    private function placedOrder(): Order
    {
        return Order::place(CustomerId::fromString('cust-int-1'), [
            new NewOrderLine(Sku::fromString('IT-WIDGET'), 'Widget', Money::of(1999, 'EUR'), Quantity::of(2)),
            new NewOrderLine(Sku::fromString('IT-GADGET'), 'Gadget', Money::of(500, 'EUR'), Quantity::of(3)),
        ], new \DateTimeImmutable('2026-07-18T12:00:00+00:00'));
    }

    private function repo(IntegrationTester $I): OrderRepository
    {
        return $I->grabService(OrderRepository::class);
    }

    private function em(IntegrationTester $I): EntityManagerInterface
    {
        return $I->grabService(EntityManagerInterface::class);
    }
}
