<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * The stage-3 read surface over real HTTP: order history straight from the
 * read_order_summary projection and the admin-ish notification-log listing.
 */
final class OrderHistoryAndNotificationsCest
{
    public function historyListsOwnOrdersNewestFirstAndOnlyOwn(AcceptanceTester $I): void
    {
        $customer = 'at-hist-'.bin2hex(random_bytes(5));
        $firstOrder = $this->placeOrder($I, $customer, priceMinor: 1000, quantity: 2);
        $secondOrder = $this->placeOrder($I, $customer, priceMinor: 2500, quantity: 1);

        $this->asCustomer($I, $customer);
        $I->sendGet('/api/orders');
        $I->seeResponseCodeIs(200);

        $ids = $I->grabDataFromResponseByJsonPath('$.orders[*].id');
        $I->assertSame([$secondOrder, $firstOrder], $ids); // newest first
        $I->seeResponseContainsJson(['orders' => [
            ['id' => $secondOrder, 'status' => 'placed', 'total' => ['amountMinor' => 2500, 'currency' => 'EUR'], 'lineCount' => 1],
            ['id' => $firstOrder, 'status' => 'placed', 'total' => ['amountMinor' => 2000, 'currency' => 'EUR'], 'lineCount' => 1],
        ]]);

        // Another customer's history is empty — orders are scoped, never
        // leaked:
        $this->asCustomer($I, 'at-hist-other-'.bin2hex(random_bytes(4)));
        $I->sendGet('/api/orders');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['orders' => []]);
    }

    public function historyRequiresTheCustomerHeader(AcceptanceTester $I): void
    {
        $I->sendGet('/api/orders');
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function notificationListingValidatesOrderId(AcceptanceTester $I): void
    {
        $I->sendGet('/api/notifications');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'orderId']]]);

        $I->sendGet('/api/notifications?orderId=');
        $I->seeResponseCodeIs(422);

        $I->sendGet('/api/notifications?orderId=not-a-uuid');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'orderId', 'message' => 'orderId must be a UUID.']]]);
    }

    public function notificationsForAnUnknownOrderAreAnEmptyList(AcceptanceTester $I): void
    {
        $I->sendGet('/api/notifications?orderId=123e4567-e89b-42d3-a456-426614174000');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['notifications' => []]);
    }

    // ------------------------------------------------------------- plumbing

    private function placeOrder(AcceptanceTester $I, string $customer, int $priceMinor, int $quantity): string
    {
        $sku = 'HIST-'.strtoupper(bin2hex(random_bytes(4)));

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'History Product '.$sku,
            'priceMinor' => $priceMinor,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 10]);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => $quantity]);
        $I->seeResponseCodeIs(200);
        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(201);

        $orderId = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->assertIsString($orderId);

        return $orderId;
    }

    private function asCustomer(AcceptanceTester $I, string $customer): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Customer-Id', $customer);
    }
}
