<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * Cart over real HTTP: header auth, live pricing via the ACL, and the C1-C3
 * validation table. Random SKUs and customer ids per scenario — these run
 * against the dev database like any client would.
 */
final class CartApiCest
{
    public function cartEndpointsRequireTheCustomerHeader(AcceptanceTester $I): void
    {
        $I->sendGet('/api/cart');
        $I->seeResponseCodeIs(401);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/cart/lines/ABC-123', ['quantity' => 1]);
        $I->seeResponseCodeIs(401);

        $I->sendDelete('/api/cart/lines/ABC-123');
        $I->seeResponseCodeIs(401);
    }

    public function anUntouchedCartIsEmpty(AcceptanceTester $I): void
    {
        $this->asCustomer($I, self::randomCustomer());
        $I->sendGet('/api/cart');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['lines' => [], 'total' => null]);
    }

    public function putLineShowsLivePricesAndTotals(AcceptanceTester $I): void
    {
        $customer = self::randomCustomer();
        $skuA = $this->seedProduct($I, 1999, 'EUR');
        $skuB = $this->seedProduct($I, 500, 'EUR');

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$skuA, ['quantity' => 2]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'lines' => [[
                'sku' => $skuA,
                'unitPrice' => ['amountMinor' => 1999, 'currency' => 'EUR'],
                'quantity' => 2,
                'lineTotal' => ['amountMinor' => 3998, 'currency' => 'EUR'],
            ]],
            'total' => ['amountMinor' => 3998, 'currency' => 'EUR'],
        ]);

        $I->sendPut('/api/cart/lines/'.$skuB, ['quantity' => 3]);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 5498, 'currency' => 'EUR']]);

        // PUT replaces, never sums:
        $I->sendPut('/api/cart/lines/'.$skuA, ['quantity' => 1]);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 3499, 'currency' => 'EUR']]);
    }

    public function aPriceChangeReflectsInTheCartImmediately(AcceptanceTester $I): void
    {
        // C2 made visible: carts store no prices, so a re-price shows up live.
        $customer = self::randomCustomer();
        $sku = $this->seedProduct($I, 1000, 'EUR');

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 1]);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 1000, 'currency' => 'EUR']]);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/api/products/'.$sku, ['priceMinor' => 2500, 'currency' => 'EUR']);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, $customer);
        $I->sendGet('/api/cart');
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 2500, 'currency' => 'EUR']]);
    }

    public function quantityValidationTable(AcceptanceTester $I): void
    {
        $sku = $this->seedProduct($I, 1000, 'EUR');
        $this->asCustomer($I, self::randomCustomer());

        foreach ([0, 100, -1] as $bad) {
            $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => $bad]);
            $I->seeResponseCodeIs(422);
            $I->seeResponseContainsJson(['errors' => [['field' => 'quantity']]]);
        }

        $I->sendPut('/api/cart/lines/'.$sku, []);
        $I->seeResponseCodeIs(422);

        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 'two']);
        $I->seeResponseCodeIs(400);
    }

    public function unknownAndInactiveProductsAreRejected(AcceptanceTester $I): void
    {
        $this->asCustomer($I, self::randomCustomer());
        $I->sendPut('/api/cart/lines/NOPE-'.strtoupper(bin2hex(random_bytes(3))), ['quantity' => 1]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'sku', 'message' => 'Unknown or inactive product.']]]);

        $sku = $this->seedProduct($I, 1000, 'EUR');
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/api/products/'.$sku, ['active' => false]);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, self::randomCustomer());
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 1]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'sku', 'message' => 'Unknown or inactive product.']]]);
    }

    public function mixedCurrenciesAreRejected(AcceptanceTester $I): void
    {
        $eurSku = $this->seedProduct($I, 1000, 'EUR');
        $usdSku = $this->seedProduct($I, 1000, 'USD');

        $this->asCustomer($I, self::randomCustomer());
        $I->sendPut('/api/cart/lines/'.$eurSku, ['quantity' => 1]);
        $I->seeResponseCodeIs(200);

        $I->sendPut('/api/cart/lines/'.$usdSku, ['quantity' => 1]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['message' => 'Cart currency mismatch.']]]);
    }

    public function aDeactivatedProductsLineIsOmittedFromTheViewButStillDeletable(AcceptanceTester $I): void
    {
        // The documented decided edge: a carted product that goes inactive
        // cannot be priced, so GET omits the line (a GET stays renderable);
        // the line stays in the cart and remains DELETE-able.
        $customer = self::randomCustomer();
        $skuA = $this->seedProduct($I, 1000, 'EUR');
        $skuB = $this->seedProduct($I, 500, 'EUR');

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$skuA, ['quantity' => 1]);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/cart/lines/'.$skuB, ['quantity' => 2]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 2000, 'currency' => 'EUR']]);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/api/products/'.$skuB, ['active' => false]);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, $customer);
        $I->sendGet('/api/cart');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['lines' => [['sku' => $skuA]]]);
        $I->dontSeeResponseContainsJson(['lines' => [['sku' => $skuB]]]);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 1000, 'currency' => 'EUR']]);

        // Still in the cart, still deletable:
        $I->sendDelete('/api/cart/lines/'.$skuB);
        $I->seeResponseCodeIs(204);
        $I->sendGet('/api/cart');
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 1000, 'currency' => 'EUR']]);
    }

    public function aLineRepricedIntoAnotherCurrencyIsOmittedFromTheView(AcceptanceTester $I): void
    {
        // Regression: summing a USD line into an EUR total threw
        // CurrencyMismatch past the controller — GET /api/cart was a 500.
        // Same decided edge as deactivation: the line is unpriceable IN THIS
        // CART, so the view omits it; checkout would 409.
        $customer = self::randomCustomer();
        $skuA = $this->seedProduct($I, 1000, 'EUR');
        $skuB = $this->seedProduct($I, 500, 'EUR');

        $this->asCustomer($I, $customer);
        $I->sendPut('/api/cart/lines/'.$skuA, ['quantity' => 1]);
        $I->seeResponseCodeIs(200);
        $I->sendPut('/api/cart/lines/'.$skuB, ['quantity' => 1]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 1500, 'currency' => 'EUR']]);

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/api/products/'.$skuB, ['priceMinor' => 500, 'currency' => 'USD']);
        $I->seeResponseCodeIs(200);

        $this->asCustomer($I, $customer);
        $I->sendGet('/api/cart');
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['lines' => [['sku' => $skuA]]]);
        $I->dontSeeResponseContainsJson(['lines' => [['sku' => $skuB]]]);
        $I->seeResponseContainsJson(['total' => ['amountMinor' => 1000, 'currency' => 'EUR']]);

        // Still in the cart, still deletable:
        $I->sendDelete('/api/cart/lines/'.$skuB);
        $I->seeResponseCodeIs(204);
    }

    public function deleteRemovesALineOnce(AcceptanceTester $I): void
    {
        $sku = $this->seedProduct($I, 1000, 'EUR');
        $this->asCustomer($I, self::randomCustomer());
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 2]);
        $I->seeResponseCodeIs(200);

        $I->sendDelete('/api/cart/lines/'.$sku);
        $I->seeResponseCodeIs(204);

        $I->sendGet('/api/cart');
        $I->seeResponseContainsJson(['lines' => [], 'total' => null]);

        $I->sendDelete('/api/cart/lines/'.$sku);
        $I->seeResponseCodeIs(404);
    }

    // ------------------------------------------------------------- plumbing

    private function seedProduct(AcceptanceTester $I, int $priceMinor, string $currency): string
    {
        $sku = 'CART-'.strtoupper(bin2hex(random_bytes(4)));
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'Cart Product '.$sku,
            'priceMinor' => $priceMinor,
            'currency' => $currency,
        ]);
        $I->seeResponseCodeIs(201);

        return $sku;
    }

    private function asCustomer(AcceptanceTester $I, string $customer): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->haveHttpHeader('X-Customer-Id', $customer);
    }

    private static function randomCustomer(): string
    {
        return 'at-cart-'.bin2hex(random_bytes(5));
    }
}
