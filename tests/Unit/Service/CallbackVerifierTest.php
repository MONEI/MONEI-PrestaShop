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
    /** @var array<string, array{orderId: string, paid: bool}> monei2_payment rows by payment id */
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
        $this->rows = [self::ID => ['orderId' => '1001', 'paid' => false]];
        $this->remote = self::paymentFields('SUCCEEDED');
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

    private function verifier(InMemoryConfigurationStore $store): CallbackVerifier
    {
        return new CallbackVerifier(
            $store,
            0,
            0,
            function () {
                return $this->now;
            },
            function (string $paymentId) {
                return $this->rows[$paymentId] ?? null;
            },
            function (string $mode, string $id) {
                $this->fetches[] = [$mode, $id];
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

        $this->assertSame([['test', self::ID]], $this->fetches);
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
    public function testAFetchedPaymentThatDoesNotFitChangesNothing(array $remote, bool $paid): void
    {
        $this->remote = $remote;
        $this->rows[self::ID]['paid'] = $paid;

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame([200, null], [$decision['status'], $decision['payment']]);
        $this->assertCount(1, $this->fetches);
    }

    public function paymentsThatChangeNothing(): array
    {
        return [
            'forged SUCCEEDED, MONEI says PENDING' => [self::paymentFields('PENDING'), false],
            'FAILED for an order already paid' => [self::paymentFields('FAILED'), true],
            'another orderId' => [self::paymentFields('SUCCEEDED', ['orderId' => '1002']), false],
            'another payment id' => [self::paymentFields('SUCCEEDED', ['id' => str_repeat('b', 32)]), false],
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
     * Bug: a status this SDK does not know makes the fetch fail with 503, so MONEI
     * retries a callback that can never settle.
     */
    public function testAFetchedStatusTheSdkDoesNotKnowAnswers200(): void
    {
        $this->remote = self::paymentFields('SOMETHING_NEW');

        $decision = $this->verifier(self::connectedShop())->verify(self::body(self::paymentFields('SUCCEEDED')), null);

        $this->assertSame([200, null], [$decision['status'], $decision['payment']]);
        $this->assertCount(1, $this->fetches);
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
            $this->rows[sprintf('%032x', $payment)] = ['orderId' => '1001', 'paid' => false];
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
     * Bug: a manual pk_ shop loses its v1 check (a forged body settles an order), or
     * starts to fetch.
     */
    public function testAManualPkShopVerifiesV1AsToday(): void
    {
        $store = self::store(['MONEI_PRODUCTION_MODE' => '0', 'MONEI_TEST_API_KEY' => 'pk_test_manual']);
        $body = self::body($this->remote);
        $verifier = $this->verifier($store);

        $valid = $verifier->verify($body, self::sign($body, 'v1', 'pk_test_manual'));
        $forged = $verifier->verify($body, self::sign($body, 'v1', 'pk_test_other'));
        $absent = $verifier->verify($body, null);

        $this->assertSame([200, 401, 401], [$valid['status'], $forged['status'], $absent['status']]);
        $this->assertSame('SUCCEEDED', $valid['payment']->getStatus());
        $this->assertSame([], $this->fetches);
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
     * Bug: a callback of the other mode is checked with the selected mode's key, so
     * Live callbacks of a shop in test mode fail. Each mode verifies with its own key.
     */
    public function testLivemodePicksTheKeyOfItsMode(): void
    {
        $store = self::store([
            'MONEI_PRODUCTION_MODE' => '0',
            'MONEI_TEST_API_KEY' => 'pk_test_manual',
            'MONEI_API_KEY' => 'pk_live_manual',
        ]);
        $body = self::body(self::paymentFields('SUCCEEDED', ['livemode' => true]));

        $live = $this->verifier($store)->verify($body, self::sign($body, 'v1', 'pk_live_manual'));
        $test = $this->verifier($store)->verify($body, self::sign($body, 'v1', 'pk_test_manual'));

        $this->assertSame([200, 401], [$live['status'], $test['status']]);
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
