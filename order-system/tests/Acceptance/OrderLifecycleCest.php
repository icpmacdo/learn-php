<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * The spec's done-when, exercised over real HTTP: full lifecycles with stock
 * movements visible through the public API, every payment failure mode, and
 * cancellation with stock release. (Refund-window EXPIRY lives in the unit
 * suite — HTTP tests cannot time-travel; the PRD says so honestly.).
 */
final class OrderLifecycleCest
{
    public function fullHappyLifecyclePlacedPaidShipped(AcceptanceTester $I): void
    {
        [$sku, $customer] = $this->seed($I, priceMinor: 2500, onHand: 10, cartQuantity: 3);

        // Checkout: 201, placed, prices frozen, total = 3 x 2500.
        $orderId = $this->checkout($I);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'status' => 'placed',
            'currency' => 'EUR',
            'total' => ['amountMinor' => 7500, 'currency' => 'EUR'],
            'lines' => [['sku' => $sku, 'quantity' => 3, 'unitPrice' => ['amountMinor' => 2500, 'currency' => 'EUR']]],
            'paidAt' => null,
            'transactionId' => null,
        ]);

        // Stock reserved, visible through the public stock endpoint:
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 10, 'reserved' => 3, 'available' => 7]);

        // The cart was consumed:
        $I->sendGet('/api/cart');
        $I->seeResponseContainsJson(['lines' => [], 'total' => null]);

        // Pay:
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'paid']);
        $transactionId = $I->grabDataFromResponseByJsonPath('$.transactionId')[0];
        $I->assertIsString($transactionId);
        $I->assertStringStartsWith('fake_', $transactionId);

        // Ship (admin-ish, no header needed):
        $I->sendPost('/api/orders/'.$orderId.'/ship');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'shipped']);

        // Stock committed out of the warehouse:
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 7, 'reserved' => 0, 'available' => 7]);

        // Detail shows the full history:
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['id' => $orderId, 'status' => 'shipped']);

        // Order history (read_order_summary projection) tracked every step:
        $I->sendGet('/api/orders');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['orders' => [[
            'id' => $orderId,
            'status' => 'shipped',
            'total' => ['amountMinor' => 7500, 'currency' => 'EUR'],
            'lineCount' => 1,
        ]]]);

        // The notification log holds confirmation + receipt + shipment, in
        // lifecycle order, addressed to this customer:
        $I->sendGet('/api/notifications?orderId='.$orderId);
        $I->seeResponseCodeIs(200);
        $types = $I->grabDataFromResponseByJsonPath('$.notifications[*].type');
        $I->assertSame(['order_confirmation', 'payment_receipt', 'shipment_notice'], $types);
        $I->seeResponseContainsJson(['notifications' => [
            ['type' => 'order_confirmation', 'customerId' => $customer, 'orderId' => $orderId, 'subject' => 'Order confirmation — '.$orderId],
            ['type' => 'payment_receipt', 'customerId' => $customer, 'orderId' => $orderId],
            ['type' => 'shipment_notice', 'customerId' => $customer, 'orderId' => $orderId],
        ]]);
    }

    public function declinedPaymentLeavesTheOrderPayable(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_declined']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Payment was declined.']]]);

        // Still placed, still payable:
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseContainsJson(['status' => 'placed']);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'paid']);
    }

    public function timeoutOnceThenSuccessfulRetry(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 2);
        $orderId = $this->checkout($I);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_timeout_once']);
        $I->seeResponseCodeIs(502);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);

        // Order untouched, stock still reserved:
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseContainsJson(['status' => 'placed']);
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['reserved' => 2]);

        // Same token, new HTTP request: the persisted attempt count approves.
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_timeout_once']);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'paid']);
    }

    public function gatewayErrorIs502EveryTime(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_error']);
            $I->seeResponseCodeIs(502);
        }
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseContainsJson(['status' => 'placed']);
    }

    public function unknownTokenIs422(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_visa']);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'paymentMethodToken']]]);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => '']);
        $I->seeResponseCodeIs(422);

        // Whitespace-shaped tokens pass NotBlank but not the shape rule —
        // they must be a 422 at the DTO, never an escaped VO exception (500):
        foreach (['   ', 'tok abc'] as $badShape) {
            $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => $badShape]);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContainsJson(['errors' => [['field' => 'paymentMethodToken']]]);
        }
    }

    public function cancellingAPlacedOrderReleasesTheStock(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 8, cartQuantity: 5);
        $orderId = $this->checkout($I);

        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['reserved' => 5, 'available' => 3]);

        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'cancelled']);

        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 8, 'reserved' => 0, 'available' => 8]);

        // History follows; the log shows the confirmation only (the PRD
        // sends nothing on cancellation):
        $I->sendGet('/api/orders');
        $I->seeResponseContainsJson(['orders' => [['id' => $orderId, 'status' => 'cancelled']]]);
        $I->sendGet('/api/notifications?orderId='.$orderId);
        $types = $I->grabDataFromResponseByJsonPath('$.notifications[*].type');
        $I->assertSame(['order_confirmation'], $types);
    }

    public function cancellingAPaidOrderInsideTheWindowReleasesTheStock(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 8, cartQuantity: 2);
        $orderId = $this->checkout($I);

        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(200);

        // Paid seconds ago — well inside the 24h refund window:
        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['status' => 'cancelled']);

        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 8, 'reserved' => 0]);
    }

    public function cancellingAfterShipmentIs409AndStockStaysCommitted(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 8, cartQuantity: 2);
        $orderId = $this->checkout($I);
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->sendPost('/api/orders/'.$orderId.'/ship');
        $I->seeResponseCodeIs(200);

        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Cannot cancel an order that is shipped.']]]);

        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 6, 'reserved' => 0]); // still gone
    }

    public function doubleCancellationIs409(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(200);
        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(409);
    }

    public function stateMachineConflictsAre409(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        // Shipping an unpaid order:
        $I->sendPost('/api/orders/'.$orderId.'/ship');
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['message' => 'Cannot ship an order that is placed.']]]);

        // Paying twice:
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(200);
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['message' => 'Cannot pay an order that is paid.']]]);
    }

    public function insufficientStockRejectsCheckoutAtomically(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 2, cartQuantity: 5);

        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Insufficient stock for '.$sku.'.']]]);

        // Nothing happened: stock untouched, cart intact.
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 2, 'reserved' => 0, 'available' => 2]);
        $I->sendGet('/api/cart');
        $I->seeResponseContainsJson(['lines' => [['sku' => $sku, 'quantity' => 5]]]);

        // Raise stock, retry the same cart: now it goes through.
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 5]);
        $I->seeResponseCodeIs(200);
        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(201);
    }

    public function checkoutWithAnEmptyCartIs422(AcceptanceTester $I): void
    {
        $this->asCustomer($I, self::randomCustomer());
        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Cart is empty.']]]);
    }

    public function checkoutWithADeactivatedProductIs409(AcceptanceTester $I): void
    {
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);

        $I->sendPatch('/api/products/'.$sku, ['active' => false]);
        $I->seeResponseCodeIs(200);

        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['message' => 'Product '.$sku.' is no longer available.']]]);
    }

    public function checkoutWithACurrencyRepricedProductIs409(AcceptanceTester $I): void
    {
        // The sibling of the deactivation conflict above: the product was
        // re-priced into another currency than the cart's after it was added
        // — the same "world changed underneath the cart" edge, refused
        // loudly instead of silently checking out in the wrong currency.
        [$sku] = $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);

        $I->sendPatch('/api/products/'.$sku, ['priceMinor' => 1000, 'currency' => 'USD']);
        $I->seeResponseCodeIs(200);

        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(409);
        $I->seeResponseContainsJson(['errors' => [['message' => 'Product '.$sku.' is no longer available.']]]);
    }

    public function anotherCustomersOrderIsInvisible(AcceptanceTester $I): void
    {
        $this->seed($I, priceMinor: 1000, onHand: 5, cartQuantity: 1);
        $orderId = $this->checkout($I);

        // Owner sees it:
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseCodeIs(200);

        // Anyone else gets 404 on every verb — existence hidden, never 403:
        $this->asCustomer($I, 'at-intruder-'.bin2hex(random_bytes(4)));
        $I->sendGet('/api/orders/'.$orderId);
        $I->seeResponseCodeIs(404);
        $I->sendPost('/api/orders/'.$orderId.'/cancel');
        $I->seeResponseCodeIs(404);
        $I->sendPost('/api/orders/'.$orderId.'/payment', ['paymentMethodToken' => 'tok_success']);
        $I->seeResponseCodeIs(404);
    }

    public function orderEndpointsRequireTheCustomerHeader(AcceptanceTester $I): void
    {
        $someUuid = '019f77bf-19a6-71ab-b125-5047cd5321e1';

        // No X-Customer-Id header set anywhere in this test.
        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(401);
        $I->sendGet('/api/orders/'.$someUuid);
        $I->seeResponseCodeIs(401);
        $I->sendPost('/api/orders/'.$someUuid.'/cancel');
        $I->seeResponseCodeIs(401);
    }

    public function aWellFormedUnknownOrderIdIs404AndAMalformedOneNeverRoutes(AcceptanceTester $I): void
    {
        $this->asCustomer($I, self::randomCustomer());

        // Well-formed UUID we never issued: 404 from the lookup.
        $I->sendGet('/api/orders/123e4567-e89b-42d3-a456-426614174000');
        $I->seeResponseCodeIs(404);

        // Same for ship — the one order action with no ownership check, so
        // the intruder test above can never reach its OrderNotFound branch:
        $I->sendPost('/api/orders/123e4567-e89b-42d3-a456-426614174000/ship');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);

        // Not a UUID: the route requirement rejects it before any controller.
        $I->sendGet('/api/orders/not-a-uuid');
        $I->seeResponseCodeIs(404);
        $I->sendPost('/api/orders/not-a-uuid/ship');
        $I->seeResponseCodeIs(404);
    }

    // ------------------------------------------------------------- plumbing

    /**
     * Seed product + stock, put a cart line, return [sku, customer].
     * Leaves the customer header set for the scenario's next requests.
     *
     * @return array{0: string, 1: string}
     */
    private function seed(AcceptanceTester $I, int $priceMinor, int $onHand, int $cartQuantity): array
    {
        $sku = 'LIFE-'.strtoupper(bin2hex(random_bytes(4)));
        $customer = self::randomCustomer();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'Lifecycle Product '.$sku,
            'priceMinor' => $priceMinor,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);

        $I->sendPut('/api/stock/'.$sku, ['onHand' => $onHand]);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => $cartQuantity]);
        $I->seeResponseCodeIs(200);

        return [$sku, $customer];
    }

    private function checkout(AcceptanceTester $I): string
    {
        $I->sendPost('/api/orders');
        $orderId = $I->grabDataFromResponseByJsonPath('$.id')[0];
        $I->assertIsString($orderId);

        return $orderId;
    }

    private function asCustomer(AcceptanceTester $I, string $customer): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Customer-Id', $customer);
    }

    private static function randomCustomer(): string
    {
        return 'at-life-'.bin2hex(random_bytes(5));
    }
}
