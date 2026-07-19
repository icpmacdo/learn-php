<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * Inventory endpoints over real HTTP. Note there is deliberately no
 * "product must exist" coupling: stock for a SKU the catalog never heard of
 * is legal — the contexts share only the SKU value.
 */
final class StockApiCest
{
    public function putCreatesAndUpdatesAStockLevel(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 7]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['sku' => $sku, 'onHand' => 7, 'reserved' => 0, 'available' => 7]);

        $I->sendPut('/api/stock/'.$sku, ['onHand' => 3]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['sku' => $sku, 'onHand' => 3, 'reserved' => 0, 'available' => 3]);

        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['sku' => $sku, 'onHand' => 3]);
    }

    public function zeroOnHandIsValid(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 0]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['onHand' => 0, 'available' => 0]);
    }

    public function unknownSkuIs404(AcceptanceTester $I): void
    {
        $I->sendGet('/api/stock/NOPE-'.self::randomSku());
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function negativeOnHandIs422(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.self::randomSku(), ['onHand' => -1]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'onHand']]]);
    }

    public function missingOnHandIs422(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.self::randomSku(), []);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'onHand']]]);
    }

    public function nonIntegerOnHandIs400(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPut('/api/stock/'.self::randomSku(), ['onHand' => 'seven']);
        $I->seeResponseCodeIs(400);
    }

    public function loweringOnHandBelowReservedIs422(AcceptanceTester $I): void
    {
        // Invariant S4 at the HTTP boundary: an operator cannot set onHand
        // below what checkouts have already reserved. Needs a real
        // reservation, so this scenario walks product -> stock -> cart ->
        // checkout first.
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'S4 Boundary '.$sku,
            'priceMinor' => 1000,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 10]);
        $I->seeResponseCodeIs(200);

        $I->haveHttpHeader('X-Customer-Id', 'at-stock-'.bin2hex(random_bytes(5)));
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 6]);
        $I->seeResponseCodeIs(200);
        $I->sendPost('/api/orders');
        $I->seeResponseCodeIs(201); // reserved = 6

        // Below reserved: 422 on the onHand field (the PRD's contract table):
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 5]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'onHand']]]);

        // AT reserved is the legal boundary:
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 6]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['sku' => $sku, 'onHand' => 6, 'reserved' => 6, 'available' => 0]);
    }

    public function skuRouteRequirementRejectsTooShortSku(AcceptanceTester $I): void
    {
        // Route requirement [A-Z0-9-]{3,32}: a 2-char SKU never matches a
        // route, so this is a 404 (unknown route), not a validation 422.
        $I->sendGet('/api/stock/AB');
        $I->seeResponseCodeIs(404);
    }

    private static function randomSku(): string
    {
        return 'AT-'.strtoupper(bin2hex(random_bytes(5)));
    }
}
