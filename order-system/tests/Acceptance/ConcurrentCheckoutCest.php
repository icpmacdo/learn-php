<?php

declare(strict_types=1);

namespace App\Tests\Acceptance;

use App\Tests\Support\AcceptanceTester;

/**
 * Oversell under REAL concurrency: two customers race POST /api/orders for
 * the same last unit through nginx + separate php-fpm workers (curl_multi —
 * the REST module is sequential). Inventory's locked read (SELECT ... FOR
 * UPDATE in bySkuForUpdate) serializes the two checkouts, so exactly one
 * wins whatever the interleaving; without it both could pass the S2 guard
 * on the same snapshot and promise the single unit twice.
 */
final class ConcurrentCheckoutCest
{
    public function twoSimultaneousCheckoutsForTheLastUnitSellItOnce(AcceptanceTester $I): void
    {
        $sku = 'AT-RACE-'.strtoupper(bin2hex(random_bytes(4)));
        $customers = ['at-race-'.bin2hex(random_bytes(5)), 'at-race-'.bin2hex(random_bytes(5))];

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'Last Unit '.$sku,
            'priceMinor' => 1000,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 1]);
        $I->seeResponseCodeIs(200);

        foreach ($customers as $customer) {
            $I->haveHttpHeader('X-Customer-Id', $customer);
            $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 1]);
            $I->seeResponseCodeIs(200);
        }

        $statusCodes = $this->fireConcurrentCheckouts($customers);

        // Exactly one 201, one 409 — never two promises for one unit:
        sort($statusCodes);
        $I->assertSame([201, 409], $statusCodes);

        // And the warehouse agrees:
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseCodeIs(200);
        $I->seeResponseContainsJson(['onHand' => 1, 'reserved' => 1, 'available' => 0]);
    }

    public function theSameCartCheckedOutTwiceConcurrentlyPlacesOneOrder(AcceptanceTester $I): void
    {
        // The cart-side race: one customer, two devices, one POST /api/orders
        // each. Checkout's locked cart read serializes them — the loser
        // re-reads the consumed cart as empty (422); without the lock both
        // would see the same non-empty cart and place the order twice.
        $sku = 'AT-RACE-'.strtoupper(bin2hex(random_bytes(4)));
        $customer = 'at-race-'.bin2hex(random_bytes(5));

        $I->haveHttpHeader('Content-Type', 'application/json');
        $I->sendPost('/api/products', [
            'sku' => $sku,
            'name' => 'Double Tap '.$sku,
            'priceMinor' => 1000,
            'currency' => 'EUR',
        ]);
        $I->seeResponseCodeIs(201);
        $I->sendPut('/api/stock/'.$sku, ['onHand' => 5]);
        $I->seeResponseCodeIs(200);

        $I->haveHttpHeader('X-Customer-Id', $customer);
        $I->sendPut('/api/cart/lines/'.$sku, ['quantity' => 1]);
        $I->seeResponseCodeIs(200);

        $statusCodes = $this->fireConcurrentCheckouts([$customer, $customer]);

        sort($statusCodes);
        $I->assertSame([201, 422], $statusCodes); // one order, one "Cart is empty."

        // Exactly one order exists, holding exactly one unit:
        $I->sendGet('/api/orders');
        $I->seeResponseCodeIs(200);
        $orders = $I->grabDataFromResponseByJsonPath('$.orders[*]');
        $I->assertCount(1, $orders);
        $I->sendGet('/api/stock/'.$sku);
        $I->seeResponseContainsJson(['onHand' => 5, 'reserved' => 1, 'available' => 4]);
    }

    /**
     * @param list<string> $customers
     *
     * @return list<int> HTTP status codes, one per checkout
     */
    private function fireConcurrentCheckouts(array $customers): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($customers as $customer) {
            $handle = curl_init('http://nginx/api/orders');
            curl_setopt_array($handle, [
                \CURLOPT_POST => true,
                \CURLOPT_POSTFIELDS => '',
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT => 30,
                \CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Customer-Id: '.$customer,
                ],
            ]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.1);
            }
        } while ($running > 0 && $status === \CURLM_OK);

        $statusCodes = [];
        foreach ($handles as $handle) {
            $statusCodes[] = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);

        return $statusCodes;
    }
}
