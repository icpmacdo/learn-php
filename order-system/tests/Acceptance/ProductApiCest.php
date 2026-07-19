<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * Catalog endpoints over real HTTP (nginx -> php-fpm -> dev DB). Every
 * scenario mints its own random SKU so runs are repeatable without cleanup.
 */
final class ProductApiCest
{
    public function createsAndFetchesAProduct(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'Acceptance Widget',
            'description' => 'Bought over HTTP.',
            'priceMinor' => 1999,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson([
            'sku' => $sku,
            'name' => 'Acceptance Widget',
            'price' => ['amountMinor' => 1999, 'currency' => 'EUR'],
            'active' => true,
        ]);

        $I->sendGet('/api/products/'.$sku);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['sku' => $sku, 'name' => 'Acceptance Widget']);
    }

    public function lowercaseSkuInBodyIsNormalized(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => strtolower($sku),
            'name' => 'Lowercase',
            'priceMinor' => 100,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->seeResponseContainsJson(['sku' => $sku]);
    }

    public function duplicateSkuIs422(AcceptanceTester $I): void
    {
        $sku = self::randomSku();
        $body = ['sku' => $sku, 'name' => 'Once', 'priceMinor' => 500, 'currency' => 'EUR'];

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', $body);
        $I->seeResponseCodeIs(201);

        $I->sendPost('/api/products', $body);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'sku']]]);
    }

    public function validationErrorsAre422PerField(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => 'x', // too short
            'name' => '',
            'priceMinor' => 0, // not positive
            'currency' => 'NOPE',
        ]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'sku']]]);
        $I->seeResponseContainsJson(['errors' => [['field' => 'name']]]);
        $I->seeResponseContainsJson(['errors' => [['field' => 'priceMinor']]]);
        $I->seeResponseContainsJson(['errors' => [['field' => 'currency']]]);
    }

    public function wrongFieldTypeIs400(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => self::randomSku(),
            'name' => 'Type test',
            'priceMinor' => '1999', // string, not int: transport-level 400
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(400);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function malformedJsonIs400(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', 'not json {');
        $I->seeResponseCodeIs(400);
    }

    public function unknownProductIs404WithErrorShape(AcceptanceTester $I): void
    {
        $I->sendGet('/api/products/NOPE-'.self::randomSku());
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null, 'message' => 'Product not found.']]]);
    }

    public function listPaginatesAndShowsAvailability(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', ['sku' => $sku, 'name' => 'Listed', 'priceMinor' => 700, 'currency' => 'EUR']);
        $I->seeResponseCodeIs(201);
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 5]);
        $I->seeResponseCodeIs(200);

        // The listing is sku-ordered and the dev DB accumulates products
        // across runs, so page through until we find ours — page 1 alone
        // eventually stops containing fresh random SKUs.
        $found = $this->findInListing($I, $sku);
        $I->assertNotNull($found, 'expected '.$sku.' in the paginated listing');
        $I->assertSame('Listed', $found['name']);
        $I->assertSame(['amountMinor' => 700, 'currency' => 'EUR'], $found['price']);
        $I->assertTrue($found['active']);
        $I->assertSame(5, $found['available']);
    }

    public function badListParamsAre422(AcceptanceTester $I): void
    {
        foreach (['?page=0', '?page=abc', '?limit=0', '?limit=101', '?limit=-1'] as $query) {
            $I->sendGet('/api/products'.$query);
            $I->seeResponseCodeIs(422);
        }
    }

    public function patchUpdatesPriceNameAndActive(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', ['sku' => $sku, 'name' => 'Before', 'priceMinor' => 100, 'currency' => 'EUR']);
        $I->seeResponseCodeIs(201);

        $I->sendPatch('/api/products/'.$sku, [
            'name' => 'After',
            'priceMinor' => 2500,
            'currency' => 'EUR',
            'active' => false,
        ]);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson([
            'sku' => $sku,
            'name' => 'After',
            'price' => ['amountMinor' => 2500, 'currency' => 'EUR'],
            'active' => false,
        ]);
    }

    public function patchWithHalfAPricePairIs422(AcceptanceTester $I): void
    {
        $sku = self::randomSku();

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', ['sku' => $sku, 'name' => 'Pair', 'priceMinor' => 100, 'currency' => 'EUR']);
        $I->seeResponseCodeIs(201);

        $I->sendPatch('/api/products/'.$sku, ['priceMinor' => 2500]);
        $I->seeResponseCodeIs(422);
        $I->seeResponseContainsJson(['errors' => [['field' => 'priceMinor']]]);
    }

    public function patchUnknownProductIs404(AcceptanceTester $I): void
    {
        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPatch('/api/products/NOPE-'.self::randomSku(), ['name' => 'Ghost']);
        $I->seeResponseCodeIs(404);
    }

    public function methodNotAllowedIs405WithErrorShape(AcceptanceTester $I): void
    {
        $I->sendDelete('/api/products');
        $I->seeResponseCodeIs(405);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    public function unknownRouteIs404Json(AcceptanceTester $I): void
    {
        $I->sendGet('/api/definitely-not-a-route');
        $I->seeResponseCodeIs(404);
        $I->seeResponseContainsJson(['errors' => [['field' => null]]]);
    }

    /**
     * Page through the sku-ordered listing until the SKU is found or the
     * total is exhausted (mirrors ProductListProjectionCest::listedRow).
     *
     * @return array<string, mixed>|null
     */
    private function findInListing(AcceptanceTester $I, string $sku): ?array
    {
        for ($page = 1; $page <= 1000; ++$page) {
            $I->sendGet('/api/products?page='.$page.'&limit=100');
            $I->seeResponseCodeIs(200);
            $I->seeResponseContainsJson(['page' => $page, 'limit' => 100]);

            /** @var list<array<string, mixed>> $products */
            $products = $I->grabDataFromResponseByJsonPath('$.products[*]');
            foreach ($products as $product) {
                if (($product['sku'] ?? null) === $sku) {
                    return $product;
                }
            }

            $total = (int) $I->grabDataFromResponseByJsonPath('$.total')[0];
            if ($page * 100 >= $total) {
                return null;
            }
        }

        return null;
    }

    private static function randomSku(): string
    {
        return 'AT-'.strtoupper(bin2hex(random_bytes(5)));
    }
}
