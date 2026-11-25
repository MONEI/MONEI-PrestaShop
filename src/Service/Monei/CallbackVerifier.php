<?php

namespace PsMonei\Service\Monei;

use Monei\ApiException;
use Monei\Model\Payment;
use Monei\ObjectSerializer;
use PsMonei\Repository\ConfigurationStoreInterface;

/**
 * Decides which payment a MONEI callback may process.
 *
 * - A v2 signature that verifies with the stored signing secret: the body's payment.
 * - Else, on a mode with a scoped key (mk_): the body gives only the payment id.
 *   The payment is fetched with the shop's key and checked against its
 *   monei2_payment row. So callbacks of orders made before the connect, after a
 *   rotation, or without v2 still settle, and a forged body changes nothing.
 * - Else (a manual pk_ key): v1 with that key.
 *
 * Pure: the store, the clock, the row lookup and the fetch are injected, so the
 * unit suite can load it.
 */
final class CallbackVerifier
{
    public const QUOTA_KEY = 'MONEI_CALLBACK_FETCH_ATTEMPTS';

    private const PAYMENT_QUOTA = 5;
    private const SHOP_QUOTA = 60;
    private const WINDOW = 60;
    /**
     * The statuses an unsigned callback may move a payment to, by the status stored
     * for it. Only statuses MONEI sends callbacks for (payments-service
     * handleCallback.ts), and only forward: a replay of a status already processed
     * changes nothing, such as a SUCCEEDED for an order the merchant cancelled since.
     */
    private const FORWARD = [
        '' => ['AUTHORIZED', 'SUCCEEDED', 'FAILED', 'CANCELED'],
        'PENDING' => ['AUTHORIZED', 'SUCCEEDED', 'FAILED', 'CANCELED'],
        'EXPIRED' => ['AUTHORIZED', 'SUCCEEDED', 'FAILED', 'CANCELED'],
        'FAILED' => ['AUTHORIZED', 'SUCCEEDED', 'CANCELED'],
        'CANCELED' => ['AUTHORIZED', 'SUCCEEDED', 'FAILED'],
        'AUTHORIZED' => ['SUCCEEDED', 'CANCELED'],
        'SUCCEEDED' => ['PAID_OUT'],
    ];

    private const ID_FORMAT = '/^[0-9a-f]{32,40}\z/i';

    /** @var ConfigurationStoreInterface */
    private $store;
    /** @var int */
    private $idShopGroup;
    /** @var int */
    private $idShop;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string): (array{orderId: string, status: string, monei: bool, paid: bool, idShopGroup: int, idShop: int}|null) The monei2_payment row of a payment id, the order and the shop of its cart */
    private $findPayment;
    /** @var callable(string, string): Payment Fetch a payment with an API key */
    private $fetch;

    /**
     * @param int $idShopGroup The shop group the request came in on
     * @param int $idShop The shop the request came in on
     */
    public function __construct(ConfigurationStoreInterface $store, int $idShopGroup, int $idShop, callable $clock, callable $findPayment, callable $fetch)
    {
        $this->store = $store;
        $this->idShopGroup = $idShopGroup;
        $this->idShop = $idShop;
        $this->clock = $clock;
        $this->findPayment = $findPayment;
        $this->fetch = $fetch;
    }

    public function anyModeConnected(): bool
    {
        $scope = [$this->idShopGroup, $this->idShop];

        return self::isScopedKey($this->value(ConnectService::KEYS['live']['apiKey'], $scope))
            || self::isScopedKey($this->value(ConnectService::KEYS['test']['apiKey'], $scope));
    }

    /**
     * The payment to process, or null with the HTTP status to answer: 400 a malformed
     * callback, 401 a bad signature, 503 a failed fetch or a spent fetch quota, 200 a
     * payment that must not change the order.
     *
     * @param string $body Raw request body
     * @param string|null $signature MONEI-Signature header, null when absent
     *
     * @return array{status: int, payment: Payment|null, reason: string}
     */
    public function verify(string $body, ?string $signature): array
    {
        $data = json_decode($body);
        if (!$data instanceof \stdClass) {
            return self::refused(400, 'body');
        }
        $id = $data->id ?? null;
        $row = is_string($id) && preg_match(self::ID_FORMAT, $id) ? ($this->findPayment)($id) : null;
        // The credentials of the shop that owns the payment: a payment of another shop
        // is never settled with the keys of the shop the request came in on.
        $scope = $row === null ? [$this->idShopGroup, $this->idShop] : [$row['idShopGroup'], $row['idShop']];
        $mode = $this->modeOf($data, $scope);
        $key = $this->value(ConnectService::KEYS[$mode]['apiKey'], $scope);
        $secret = $this->value(ConnectService::KEYS[$mode]['signingSecret'], $scope);

        if ($secret !== '' && $signature !== null && self::signatureValid($body, $signature, '', $secret)) {
            return $this->signed($data, $scope);
        }
        if (self::isScopedKey($key)) {
            return $this->fetched($signature, $id, $row, $key, $scope);
        }
        // An empty key gives an HMAC that anyone can make.
        if ($key === '' || $signature === null || !self::signatureValid($body, $signature, $key, null)) {
            return self::refused(401, 'signature');
        }

        return $this->signed($data, $scope);
    }

    /**
     * A body that v2 or v1 proves. It still must be a callback of the shop's mode:
     * anyone with the test key can sign a test payment made for a live cart.
     *
     * @param int[] $scope
     */
    private function signed(\stdClass $data, array $scope): array
    {
        if (isset($data->livemode) && is_bool($data->livemode) && $data->livemode !== $this->isLive($scope)) {
            return self::refused(200, 'livemode');
        }

        return self::fromBody($data);
    }

    /**
     * @param mixed $id The body's payment id
     * @param array{orderId: string, status: string, monei: bool, paid: bool, idShopGroup: int, idShop: int}|null $row
     * @param int[] $scope
     */
    private function fetched(?string $signature, $id, ?array $row, string $key, array $scope): array
    {
        if (($signature !== null && !self::headerParses($signature))
            || !is_string($id) || !preg_match(self::ID_FORMAT, $id)) {
            return self::refused(400, 'format');
        }
        // Not a payment of this shop: nothing to retry.
        if ($row === null) {
            return self::refused(200, 'payment');
        }
        // The cart was paid with another payment module.
        if (!$row['monei']) {
            return self::refused(200, 'module');
        }
        // Temporary: MONEI retries a 503.
        if (!$this->takeQuota($id, $scope)) {
            return self::refused(503, 'quota');
        }

        try {
            $payment = ($this->fetch)($key, $id);
        } catch (\InvalidArgumentException $e) {
            // The SDK's deserialize refuses a status it does not know. A retry gets
            // the same answer, so it is not a 503.
            return self::refused(200, 'status');
        } catch (\Throwable $e) {
            return self::refused(503, 'fetch');
        }

        if ($payment->getId() !== $id) {
            return self::refused(200, 'id');
        }
        if ($row['orderId'] !== '' && (string) $payment->getOrderId() !== $row['orderId']) {
            return self::refused(200, 'orderId');
        }
        if ((bool) $payment->getLivemode() !== $this->isLive($scope)) {
            return self::refused(200, 'livemode');
        }
        $status = $payment->getStatus();
        if (!in_array($status, self::FORWARD[$row['status']] ?? [], true)) {
            return self::refused(200, 'notForward');
        }
        if ($row['paid'] && in_array($status, ['FAILED', 'CANCELED'], true)) {
            return self::refused(200, 'paid');
        }

        return self::accepted($payment);
    }

    /**
     * The mode the callback names, so each mode verifies with its own key or secret.
     *
     * @param int[] $scope
     */
    private function modeOf(\stdClass $data, array $scope): string
    {
        if (isset($data->livemode) && is_bool($data->livemode)) {
            return $data->livemode ? 'live' : 'test';
        }

        return $this->isLive($scope) ? 'live' : 'test';
    }

    /**
     * @param int[] $scope
     */
    private function isLive(array $scope): bool
    {
        return (bool) $this->value('MONEI_PRODUCTION_MODE', $scope);
    }

    /**
     * Count a fetch attempt: at most 5 a minute per payment and 60 a minute per shop.
     */
    private function takeQuota(string $paymentId, array $scope): bool
    {
        $now = ($this->clock)();
        $recent = static function ($times) use ($now): array {
            return array_values(array_filter((array) $times, static function ($time) use ($now) {
                return is_int($time) && $now - $time < self::WINDOW;
            }));
        };

        $attempts = json_decode($this->value(self::QUOTA_KEY, $scope), true);
        $attempts = is_array($attempts) ? $attempts : [];
        $shop = $recent($attempts['shop'] ?? []);
        $payments = [];
        foreach ((array) ($attempts['payments'] ?? []) as $id => $times) {
            $times = $recent($times);
            if ($times) {
                $payments[$id] = $times;
            }
        }

        $payment = $payments[$paymentId] ?? [];
        if (count($payment) >= self::PAYMENT_QUOTA || count($shop) >= self::SHOP_QUOTA) {
            return false;
        }

        $shop[] = $now;
        $payment[] = $now;
        $payments[$paymentId] = $payment;
        $this->store->set(self::QUOTA_KEY, (string) json_encode(['shop' => $shop, 'payments' => $payments]), ...$scope);

        return true;
    }

    /**
     * @param int[] $scope Shop group and shop
     */
    private function value(string $key, array $scope): string
    {
        return $this->store->get($key, ...$scope);
    }

    private static function signatureValid(string $body, string $signature, string $key, ?string $secret): bool
    {
        try {
            MoneiClientFactory::create($key, '')->verifySignature($body, $signature, $secret);

            return true;
        } catch (ApiException $e) {
            return false;
        }
    }

    /**
     * The SDK's reading of the header: comma-separated key=value elements, with a t.
     */
    private static function headerParses(string $signature): bool
    {
        $hasTimestamp = false;
        foreach (explode(',', $signature) as $element) {
            $pair = explode('=', $element, 2);
            if (count($pair) !== 2) {
                return false;
            }
            $hasTimestamp = $hasTimestamp || ($pair[0] === 't' && $pair[1] !== '');
        }

        return $hasTimestamp;
    }

    private static function isScopedKey(string $key): bool
    {
        return strpos($key, 'mk_') === 0;
    }

    private static function fromBody(\stdClass $data): array
    {
        try {
            $payment = ObjectSerializer::deserialize($data, '\Monei\Model\Payment');
        } catch (\InvalidArgumentException $e) {
            // A status this SDK does not know.
            return self::refused(200, 'status');
        }

        return self::accepted($payment);
    }

    private static function accepted(Payment $payment): array
    {
        return ['status' => 200, 'payment' => $payment, 'reason' => 'ok'];
    }

    private static function refused(int $status, string $reason): array
    {
        return ['status' => $status, 'payment' => null, 'reason' => $reason];
    }
}
