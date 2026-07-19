<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ordering;

use App\Catalog\Application\Command\AddProduct;
use App\Catalog\Application\Command\AddProductHandler;
use App\Inventory\Application\Command\SetStockLevel;
use App\Inventory\Application\Command\SetStockLevelHandler;
use App\Ordering\Application\Command\PayOrder;
use App\Ordering\Application\Command\PayOrderHandler;
use App\Ordering\Application\Command\PlaceOrder;
use App\Ordering\Application\Command\PlaceOrderHandler;
use App\Ordering\Application\Command\PutCartLine;
use App\Ordering\Application\Command\PutCartLineHandler;
use App\Ordering\Domain\IllegalOrderTransition;
use App\Ordering\Domain\OrderId;
use App\Ordering\Domain\OrderRepository;
use App\Ordering\Domain\Port\PaymentGateway;
use App\Ordering\Domain\Port\PaymentMethodToken;
use App\Ordering\Domain\Port\PaymentResult;
use App\Shared\Domain\Clock;
use App\Shared\Domain\DomainEventDispatcher;
use App\Shared\Domain\Money;
use App\Shared\Domain\TransactionBoundary;
use App\Tests\Support\IntegrationTester;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O1/O5 under CONCURRENCY: the payment flow charges the gateway OUTSIDE the
 * DB transaction (deliberately — never hold a transaction across an external
 * call), so the world can change while the charge is in flight. The handler
 * must re-read the order under a row lock before pay(), or a cancel that
 * committed mid-charge would be silently overwritten by `paid` — a
 * transition the aggregate forbids, materialized in the DB.
 *
 * The race is simulated deterministically: a gateway stand-in that approves,
 * but first commits a cancel via raw SQL — exactly what another php-fpm
 * worker's transaction looks like to this one (the handler's in-memory
 * Order stays stale).
 */
final class PaymentRaceCest
{
    private string $sku;
    private string $customer;

    public function _before(IntegrationTester $I): void
    {
        $this->sku = 'RACE-'.strtoupper(bin2hex(random_bytes(4)));
        $this->customer = 'race-'.bin2hex(random_bytes(4));
    }

    public function aCancelCommittedMidChargeIsNotOverwrittenByPay(IntegrationTester $I): void
    {
        $I->grabService(AddProductHandler::class)->handle(
            new AddProduct($this->sku, 'Race Widget', null, 1000, 'EUR'),
        );
        $I->grabService(SetStockLevelHandler::class)->handle(new SetStockLevel($this->sku, 5));
        $I->grabService(PutCartLineHandler::class)->handle(
            new PutCartLine($this->customer, $this->sku, 1),
        );
        $order = $I->grabService(PlaceOrderHandler::class)->handle(new PlaceOrder($this->customer));
        $orderId = $order->id()->value;

        $connection = $I->grabService(EntityManagerInterface::class)->getConnection();
        $racingGateway = new class($connection) implements PaymentGateway {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function charge(OrderId $orderId, Money $amount, PaymentMethodToken $token): PaymentResult
            {
                // The concurrent cancel commits while the charge is in flight:
                $this->connection->executeStatement(
                    "UPDATE ordering_order SET status = 'cancelled', cancelled_at = NOW() WHERE id = ?",
                    [$orderId->value],
                );

                return PaymentResult::approved('fake_race');
            }
        };

        $handler = new PayOrderHandler(
            $I->grabService(OrderRepository::class),
            $racingGateway,
            $I->grabService(DomainEventDispatcher::class),
            $I->grabService(TransactionBoundary::class),
            $I->grabService(Clock::class),
        );

        // The locked re-read sees the committed cancel: 409, not a flush.
        $I->expectThrowable(IllegalOrderTransition::class, function () use ($handler, $orderId): void {
            $handler->handle(new PayOrder($this->customer, $orderId, 'tok_success'));
        });

        // The DB keeps the cancel — the regression flushed `paid` over it:
        $I->grabService(EntityManagerInterface::class)->clear();
        $status = $connection->fetchOne('SELECT status FROM ordering_order WHERE id = ?', [$orderId]);
        $I->assertSame('cancelled', $status);

        // And OrderPaid never dispatched — no receipt in the log:
        $receipts = $connection->fetchOne(
            "SELECT COUNT(*) FROM notification_log WHERE order_id = ? AND type = 'payment_receipt'",
            [$orderId],
        );
        $I->assertSame(0, (int) $receipts);
    }
}
