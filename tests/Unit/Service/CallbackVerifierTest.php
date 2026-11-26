<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use Monei\ApiException;
use Monei\Model\Payment;
use Monei\ObjectSerializer;
use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\CallbackVerifier;
use PsMonei\Tests\Unit\Service\Fake\InMemoryConfigurationStore;

/**
 * CallbackVerifier: which payment a MONEI callback may process.
 *
 * The fetch returns a real Monei\Model\Payment, deserialized from a JSON answer the
 * way the SDK does it, so the fetch path is checked against the SDK's model, not a
 * mock. The amount check is not here: createOrUpdateOrder and its
 * PaymentAmountMatcher own it. Every test names the bug it catches.
 */
class CallbackVerifierTest extends TestCase
{
    private const ID = 'af6029f80f5fc73a8ad2753eea0b1be0';
    private const SECRET = 'mksec_test_secret';
    private const KEY = 'mk_test_key';

    /** @var int */
    private $now = 1700000000;
    /** @var array<string, array{orderId: string, status: string, monei: bool, paid: bool}> monei2_payment rows by payment id */
    private $rows = [];
    /** @var array<string, mixed> Fields of the payment that MONEI answers */
    private $remote = [];
    /** @var bool */
    private $fetchFails = false;
    /** @var array[] */
    private $fetches = [];

    protected function setUp(): void
    {
        // A row without an order: the order is created later, from the cart.
        $this->rows = [self::ID => self::row()];
        $this->remote = self::paymentFields('SUCCEEDED');
    }

    private static function row(array $overrides = []): array
    {
        return $overrides + ['orderId' => '1001', 'status' => 'PENDING', 'monei' => true, 'paid' => false, 'idShopGroup' => 0, 'idShop' => 0];
    }

    private static function paymentFields(string $status, array $overrides = []): array
    {
        return $overrides + [
            'id' => self::ID,
            'orderId' => '1001',
            'amount' => 1000,
            'currency' => 'EUR',
            'livemode' => false,
            'status' => $status,
            'statusCode' => 'E000',
            'statusMessage' => 'Transaction approved',
        ];
    }

    private static function store(array $values): InMemoryConfigurationStore
    {
        $store = new InMemoryConfigurationStore();
        foreach ($values as $key => $value) {
            $store->set($key, $value, 0, 0);
        }

        return $store;
    }

    private static function connectedShop(): InMemoryConfigurationStore
    {
        return self::store([
            'MONEI_PRODUCTION_MODE' => '0',
            'MONEI_TEST_API_KEY' => self::KEY,
            'MONEI_TEST_SIGNING_SECRET' => self::SECRET,
        ]);
    }

    private static function manualShop(): InMemoryConfigurationStore
    {
        return self::store(['MONEI_PRODUCTION_MODE' => '0', 'MONEI_TEST_API_KEY' => 'pk_test_manual']);
    }

    /**
     * @param int[] $requestScope The shop group and shop the request came in on
     */
    private function verifier(InMemoryConfigurationStore $store, array $requestScope = [0, 0]): CallbackVerifier
    {
        return new CallbackVerifier(
            $store,
            $requestScope[0],
            $requestScope[1],
            function () {
                return $this->now;
            },
            function (string $paymentId) {
                return $this->rows[$paymentId] ?? null;
            },
            function (string $apiKey, string $id) {
                $this->fetches[] = [$apiKey, $id];
                if ($this->fetchFails) {
                    throw new ApiException('[0] cURL error 28', 0);
                }

                return ObjectSerializer::deserialize(json_decode((string) json_encode($this->remote)), '\Monei\Model\Payment');
            }
        );
    }

    private static function sign(string $body, string $element, string $key): string
    {
        return 't=1700000000,' . $element . '=' . hash_hmac('SHA256', '1700000000.' . $body, $key);
    }

    private static function body(array $fields): string
    {
        return (string) json_encode($fields);
    }

    /**
     * The fields createOrUpdateOrder and the order history read.
     */
    private static function six(Payment $payment): array
    {
        return [
            'id' => $payment->getId(),
            'orderId' => $payment->getOrderId(),
            'amount' => $payment->getAmount(),
            'status' => $payment->getStatus(),
            'statusCode' => $payment->getStatusCode(),
            'statusMessage' => $payment->getStatusMessage(),
        ];
    }

    /**
     * Bug: a connected shop fetches on every callback, or drops a body that v2 proves.
     */
    public function testAValidV2ReturnsTheBodyPaymentWithoutAFetch(): void
    {
        $body = self::body(self::paymentFields('AUTHORIZED'));

        $decision = $this->verifier(self::connectedShop())->verify($body, self::sign($body, 'v2', self::SECRET));

        $this->assertSame(200, $decision['status']);
        $this->assertSame('AUTHORIZED', $decision['payment']->getStatus());
        $this->assertSame([], $this->fetches);
    }

    /**
     * Bug: a callback that v2 does not prove changes the order from its body. The v1
     * row is a callback of an order made before the connect: it settles through the
     * fetched payment. The row has no order yet, as every first callback.
     *
     * @dataProvider unprovenSignatures
     */
    public function testAConnectedShopFetchesThePaymentAndIgnoresTheBody(string $elementKey, ?string $element): void
    {
        $body = self::body(self::paymentFields('SUCCEEDED', ['statusMessage' => 'from the body']));
        $signature = $element === null ? null : self::sign($body, $element, $elementKey);

        $decision = $this->verifier(self::connectedShop())->verify($body, $signature);

        $this->assertSame([[self::KEY, self::ID]], $this->fetches);
        $this->assertSame(200, $decision['status']);
        $this->assertSame([
            'id' => self::ID,
            'orderId' => '1001',
            'amount' => 1000,
            'status' => 'SUCCEEDED',
            'statusCode' => 'E000',
            'statusMessage' => 'Transaction approved',
        ], self::six($decision['payment']));
    }

    public function unprovenSignatures(): array
    {
        return [
            'v1 only, the old pk_ key' => ['pk_test_old', 'v1'],
            'v2 of a replaced secret' => ['mksec_test_replaced', 'v2'],
            'no header' => ['', null],
        ];
    }

    /**
     * Bug: the body path hands createOrUpdateOrder a payment with fields dropped (the
     * model constructor reads snake_case keys, so `new Payment($body)` loses orderId),
     * and a signed callback no longer finds its cart.
     */
    public function testBothPathsReturnTheSameFields(): void
    {
        $expected = [
            'id' => self::ID,
            'orderId' => '1001',
            'amount' => 1000,
            'status' => 'SUCCEEDED',
            'statusCode' => 'E000',
            'statusMessage' => 'Transaction approved',
        ];
        $body = self::body($this->remote);

        $signed = $this->verifier(self::connectedShop())->verify($body, self::sign($body, 'v2', self::SECRET));
        $fetched = $this->verifier(self::connectedShop())->verify($body, null);

        $this->assertSame($expected, self::six($signed['payment']));
        $this->assertSame($expected, self::six($fetched['payment']));
    }

    /**
     * Bug: a forged body, or a fetched payment that does not belong to the row, changes
     * the order. Each answers 200, so MONEI does not retry.
     *
     * @dataProvider paymentsThatChangeNothing
     */
    public function testAFetchedPaymentThatDoesNotFitChangesNothing(array $remote): void
    {
        $this->remote = $remote;

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame([200, null], [$decision['status'], $decision['payment']]);
        $this->assertCount(1, $this->fetches);
    }

    public function paymentsThatChangeNothing(): array
    {
        return [
            'forged SUCCEEDED, MONEI says PENDING' => [self::paymentFields('PENDING')],
            'another orderId' => [self::paymentFields('SUCCEEDED', ['orderId' => '1002'])],
            'another payment id' => [self::paymentFields('SUCCEEDED', ['id' => str_repeat('b', 32)])],
            'a live payment on a shop in test mode' => [self::paymentFields('SUCCEEDED', ['livemode' => true])],
        ];
    }

    /**
     * Bug: a failed fetch answers 200 or settles from the body, so MONEI never retries
     * and the order stays open.
     */
    public function testAFailedFetchAnswers503(): void
    {
        $this->fetchFails = true;

        $decision = $this->verifier(self::connectedShop())->verify(self::body($this->remote), null);

        $this->assertSame([503, null], [$decision['status'], $decision['payment']]);
    }

    /**
     * Bug: a body that cannot be a callback of this shop makes the shop call MONEI (a
     * fetch amplifier).
     *
     * @dataProvider bodiesRefusedBeforeAFetch
     */
    public function testAFailedPreCheckAnswers400WithNoFetch(array $fields, ?string $signature): void
    {
        $decision = $this->verifier(self::connectedShop())->verify(self::body($fields), $signature);

        $this->assertSame(400, $decision['status']);
        $this->assertSame([], $this->fetches);
    }

    public function bodiesRefusedBeforeAFetch(): array
    {
        return [
            'malformed id' => [self::paymentFields('SUCCEEDED', ['id' => '../payments']), null],
            'a header that does not parse' => [self::paymentFields('SUCCEEDED'), 'v1=abc'],
        ];
    }

    /**
     * Bug: a callback for a payment this shop never made makes the shop call MONEI,
     * or answers an error that MONEI retries for nothing.
     */
    public function testAPaymentIdWithoutARowAnswers200WithNoFetch(): void
    {
        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED', ['id' => str_repeat('c', 32)])), null);

        $this->assertSame([200, null], [$decision['status'], $decision['payment']]);
        $this->assertSame([], $this->fetches);
    }

    /**
     * Bug: a callback for a cart paid with another payment module makes the shop call
     * MONEI.
     */
    public function testACartPaidWithAnotherModuleAnswers200WithNoFetch(): void
    {
        $this->rows[self::ID] = self::row(['monei' => false]);

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame([200, null], [$decision['status'], $decision['payment']]);
        $this->assertSame([], $this->fetches);
    }

    /**
     * Bug: an unsigned callback replays a status already processed (a SUCCEEDED for an
     * order the merchant cancelled since), moves a payment backwards, or drops a real
     * move: a late payment of a cancelled order, the capture or the void of an
     * authorization. The order's state does not decide: the stored MONEI status does.
     *
     * @dataProvider moves
     */
    public function testOnlyAForwardMoveFromTheStoredStatusIsProcessed(array $row, string $fetched, ?string $processed, string $reason = 'notForward'): void
    {
        $this->rows[self::ID] = self::row($row);
        $this->remote = self::paymentFields($fetched);

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame($processed, $decision['payment'] === null ? null : $decision['payment']->getStatus());
        $this->assertSame([200, $processed === null ? $reason : 'ok'], [$decision['status'], $decision['reason']]);
    }

    public function moves(): array
    {
        return [
            'stored SUCCEEDED, order cancelled, fetched SUCCEEDED' => [['status' => 'SUCCEEDED', 'paid' => true], 'SUCCEEDED', null],
            'stored none, order cancelled, fetched SUCCEEDED' => [['status' => ''], 'SUCCEEDED', 'SUCCEEDED'],
            'stored PENDING, order cancelled, fetched SUCCEEDED' => [['status' => 'PENDING'], 'SUCCEEDED', 'SUCCEEDED'],
            'stored AUTHORIZED, fetched SUCCEEDED' => [['status' => 'AUTHORIZED'], 'SUCCEEDED', 'SUCCEEDED'],
            'stored AUTHORIZED, fetched CANCELED' => [['status' => 'AUTHORIZED'], 'CANCELED', 'CANCELED'],
            'stored SUCCEEDED, fetched AUTHORIZED' => [['status' => 'SUCCEEDED', 'paid' => true], 'AUTHORIZED', null],
            'stored FAILED, fetched FAILED' => [['status' => 'FAILED'], 'FAILED', null],
            'stored CANCELED, fetched CANCELED' => [['status' => 'CANCELED'], 'CANCELED', null],
            'stored REFUNDED, fetched SUCCEEDED' => [['status' => 'REFUNDED'], 'SUCCEEDED', null],
            'stored PENDING, order already paid, fetched FAILED' => [['status' => 'PENDING', 'paid' => true], 'FAILED', null, 'paid'],
        ];
    }

    /**
     * Bug: a status this SDK does not know makes the fetch fail with 503, so MONEI
     * retries a callback that can never settle.
     */
    public function testAFetchedStatusTheSdkDoesNotKnowAnswers200(): void
    {
        $this->remote = self::paymentFields('SOMETHING_NEW');

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame([200, null, 'unknownValue'], [$decision['status'], $decision['payment'], $decision['reason']]);
        $this->assertStringContainsString('PaymentStatus', $decision['detail']);
        $this->assertCount(1, $this->fetches);
    }

    /**
     * Bug: a v2-signed body with a status this SDK does not know is dropped, and the
     * log does not say why.
     */
    public function testASignedStatusTheSdkDoesNotKnowAnswers200WithTheReason(): void
    {
        $body = self::body(self::paymentFields('SOMETHING_NEW'));

        $decision = $this->verifier(self::connectedShop())->verify($body, self::sign($body, 'v2', self::SECRET));

        $this->assertSame([200, null, 'unknownValue'], [$decision['status'], $decision['payment'], $decision['reason']]);
        $this->assertStringContainsString('PaymentStatus', $decision['detail']);
    }

    /**
     * Bug: one payment makes unbounded fetches, or the quota never frees up. A spent
     * quota answers 503, so MONEI retries the callback once the minute is over.
     */
    public function testThePaymentQuotaRefusesThe6thAttemptInAMinuteAndFreesAfter61S(): void
    {
        $store = self::connectedShop();
        $body = self::body($this->remote);
        $statuses = [];
        for ($i = 0; $i < 6; ++$i) {
            $statuses[] = $this->verifier($store)->verify($body, null)['status'];
        }
        $this->now += 61;
        $statuses[] = $this->verifier($store)->verify($body, null)['status'];

        $this->assertSame([200, 200, 200, 200, 200, 503, 200], $statuses);
        $this->assertCount(6, $this->fetches);
    }

    /**
     * Bug: many payments together make unbounded fetches.
     */
    public function testTheShopQuotaRefusesThe61stAttemptInAMinute(): void
    {
        $store = self::connectedShop();
        for ($payment = 1; $payment <= 13; ++$payment) {
            $this->rows[sprintf('%032x', $payment)] = self::row();
        }
        $last = 0;
        for ($attempt = 0; $attempt < 61; ++$attempt) {
            $id = sprintf('%032x', intdiv($attempt, 5) + 1);
            $this->remote = self::paymentFields('SUCCEEDED', ['id' => $id]);
            $last = $this->verifier($store)->verify(self::body($this->remote), null)['status'];
        }

        $this->assertSame(503, $last);
        $this->assertCount(60, $this->fetches);
    }

    /**
     * Bug: a manual pk_ shop loses its v1 check, so a forged body is fetched or settles
     * an order.
     */
    public function testAManualPkShopRefusesAV1OfAnotherKey(): void
    {
        $store = self::manualShop();
        $body = self::body($this->remote);
        $verifier = $this->verifier($store);

        $forged = $verifier->verify($body, self::sign($body, 'v1', 'pk_test_other'));
        $absent = $verifier->verify($body, null);

        $this->assertSame([401, 401], [$forged['status'], $absent['status']]);
        $this->assertSame([], $this->fetches);
    }

    /**
     * Bug: a v1 body settles as it was signed. v1 does not check the timestamp, so a
     * resent SUCCEEDED body makes a paid order after the payment was canceled.
     */
    public function testAV1CallbackSettlesThePaymentFetchedWithTheShopsKey(): void
    {
        $this->remote = self::paymentFields('CANCELED');
        $body = self::body(self::paymentFields('SUCCEEDED'));

        $decision = $this->verifier(self::manualShop())->verify($body, self::sign($body, 'v1', 'pk_test_manual'));

        $this->assertSame('CANCELED', $decision['payment']->getStatus());
        $this->assertSame([['pk_test_manual', self::ID]], $this->fetches);
    }

    /**
     * Bug: the payment a v1 callback fetches is not checked against its row, so a
     * signed callback of one order settles another.
     */
    public function testAV1FetchedPaymentOfAnotherOrderChangesNothing(): void
    {
        $this->remote = self::paymentFields('SUCCEEDED', ['orderId' => '9999']);
        $body = self::body(self::paymentFields('SUCCEEDED'));

        $decision = $this->verifier(self::manualShop())->verify($body, self::sign($body, 'v1', 'pk_test_manual'));

        $this->assertSame([200, null, 'orderId'], [$decision['status'], $decision['payment'], $decision['reason']]);
    }

    /**
     * Bug: a v1 callback of a payment without a monei2_payment row is dropped, which
     * 2.2.3 processed. Its signature proves it, so it is fetched like any other.
     */
    public function testAV1CallbackWithoutARowIsFetched(): void
    {
        $this->rows = [];
        $body = self::body($this->remote);

        $decision = $this->verifier(self::manualShop())->verify($body, self::sign($body, 'v1', 'pk_test_manual'));

        $this->assertSame('SUCCEEDED', $decision['payment']->getStatus());
        $this->assertCount(1, $this->fetches);
    }

    /**
     * Bug: a replayed v1-signed body makes one MONEI request per callback, with no
     * limit; or a v1 callback is dropped with 200 instead of settling. The 6th in a
     * minute answers 503, so MONEI retries it.
     */
    public function testV1CallbacksFetchUnderThePaymentQuota(): void
    {
        $store = self::manualShop();
        $body = self::body($this->remote);
        $decisions = [];
        for ($i = 0; $i < 6; ++$i) {
            $decisions[] = $this->verifier($store)->verify($body, self::sign($body, 'v1', 'pk_test_manual'));
        }

        $this->assertSame('SUCCEEDED', $decisions[0]['payment']->getStatus());
        $this->assertSame([200, 200, 200, 200, 200, 503], array_column($decisions, 'status'));
        $this->assertCount(5, $this->fetches);
    }

    /**
     * Bug: a mode without a key accepts a v1 made with an empty key, which anyone can
     * compute.
     */
    public function testAModeWithoutAKeyRefusesAV1OfTheEmptyKey(): void
    {
        $body = self::body($this->remote);

        $decision = $this->verifier(new InMemoryConfigurationStore())->verify($body, self::sign($body, 'v1', ''));

        $this->assertSame(401, $decision['status']);
    }

    /**
     * Bug: a signed callback of the other mode settles an order: anyone with the
     * merchant's test key can sign a test payment made for a live cart. Each mode still
     * verifies with its own key, so a test signature on a live body is no live proof.
     *
     * @dataProvider signedCallbacksOfTheOtherMode
     */
    public function testASignedCallbackOfTheOtherModeChangesNothing(string $element, string $signingKey, array $expected): void
    {
        $store = self::store([
            'MONEI_PRODUCTION_MODE' => '0',
            'MONEI_TEST_API_KEY' => 'pk_test_manual',
            'MONEI_API_KEY' => 'pk_live_manual',
            'MONEI_SIGNING_SECRET' => 'mksec_live',
        ]);
        $body = self::body(self::paymentFields('SUCCEEDED', ['livemode' => true]));

        $decision = $this->verifier($store)->verify($body, self::sign($body, $element, $signingKey));

        $this->assertSame($expected, [$decision['status'], $decision['payment'], $decision['reason']]);
        $this->assertSame([], $this->fetches);
    }

    public function signedCallbacksOfTheOtherMode(): array
    {
        return [
            'v2 of the live secret, shop in test mode' => ['v2', 'mksec_live', [200, null, 'livemode']],
            'v1 of the live key, shop in test mode' => ['v1', 'pk_live_manual', [200, null, 'livemode']],
            'v1 of the test key on a live body' => ['v1', 'pk_test_manual', [401, null, 'signature']],
        ];
    }

    /**
     * Bug: on a multistore install, a callback that comes in on shop 1 for a payment
     * of shop 2 is checked, or fetched, with shop 1's credentials, so shop 1's account
     * can settle shop 2's order.
     */
    public function testThePaymentIsCheckedWithTheCredentialsOfTheShopThatOwnsIt(): void
    {
        $store = self::store(['MONEI_PRODUCTION_MODE' => '0']);
        $store->set('MONEI_TEST_API_KEY', 'pk_test_shop1', 1, 1);
        $store->set('MONEI_TEST_API_KEY', 'mk_test_shop2', 1, 2);
        $store->set('MONEI_TEST_SIGNING_SECRET', 'mksec_shop2', 1, 2);
        $this->rows[self::ID] = self::row(['idShopGroup' => 1, 'idShop' => 2]);
        $body = self::body($this->remote);
        $onShop1 = $this->verifier($store, [1, 1]);

        $shop2Signed = $onShop1->verify($body, self::sign($body, 'v2', 'mksec_shop2'));
        $shop1Signed = $onShop1->verify($body, self::sign($body, 'v1', 'pk_test_shop1'));
        $store->set('MONEI_TEST_API_KEY', 'pk_test_shop2', 1, 2);
        $manualShop2 = $onShop1->verify($body, self::sign($body, 'v1', 'pk_test_shop1'));

        $this->assertSame('SUCCEEDED', $shop2Signed['payment']->getStatus());
        // Shop 1's signature proves nothing for shop 2: its connected key fetches the payment.
        $this->assertSame([['mk_test_shop2', self::ID]], $this->fetches);
        $this->assertSame(200, $shop1Signed['status']);
        $this->assertSame([401, 'signature'], [$manualShop2['status'], $manualShop2['reason']]);
    }

    /**
     * A mixed shop: Live connected (mk_ + secret), Test on a manual pk_ key with no
     * secret. validation.php skips its early 401 here, because a mode is connected.
     *
     * Bug: a test-mode v1 callback takes the connected Live key's path, or skips the
     * fetch; or a test-mode callback without a header is fetched or settles, since
     * only the verifier refuses it on this shop.
     */
    public function testAMixedShopSettlesATestV1ThroughAFetchAndRefusesNoHeader(): void
    {
        $store = self::store([
            'MONEI_PRODUCTION_MODE' => '0',
            'MONEI_API_KEY' => 'mk_live_key',
            'MONEI_SIGNING_SECRET' => 'mksec_live',
            'MONEI_TEST_API_KEY' => 'pk_test_manual',
        ]);
        $body = self::body($this->remote);
        $verifier = $this->verifier($store);
        $this->assertTrue($verifier->anyModeConnected(), 'the early 401 is off on this shop');

        $signed = $verifier->verify($body, self::sign($body, 'v1', 'pk_test_manual'));
        $this->assertSame('SUCCEEDED', $signed['payment']->getStatus());
        $this->assertSame([['pk_test_manual', self::ID]], $this->fetches);

        $headerless = $verifier->verify($body, null);
        $this->assertSame([401, null, 'signature'], [$headerless['status'], $headerless['payment'], $headerless['reason']]);
        $this->assertCount(1, $this->fetches, 'a callback without a header fetches nothing');
    }

    /**
     * Bug: validation refuses a callback without MONEI-Signature on a shop where only
     * one mode is connected.
     */
    public function testAnyModeConnectedCountsEitherMode(): void
    {
        $liveOnly = self::store(['MONEI_API_KEY' => 'mk_live_key', 'MONEI_TEST_API_KEY' => 'pk_test_manual']);
        $manual = self::store(['MONEI_API_KEY' => 'pk_live_manual', 'MONEI_TEST_API_KEY' => 'pk_test_manual']);

        $this->assertSame([true, false], [$this->verifier($liveOnly)->anyModeConnected(), $this->verifier($manual)->anyModeConnected()]);
    }
}
