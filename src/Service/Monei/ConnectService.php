<?php

namespace PsMonei\Service\Monei;

use Monei\Connect;
use Monei\ConnectException;
use PsMonei\Repository\ConfigurationStoreInterface;

/**
 * "Connect with MONEI": the start, the single-use state of the callback, the test
 * call of each new key, and the swap.
 *
 * Pure: the store, the clock, the token exchange and the test call are injected,
 * so the unit suite can load it.
 */
final class ConnectService
{
    public const CLIENT = 'prestashop';
    public const STATE_PREFIX = 'MONEI_CONNECT_STATE_';
    /** Seconds: the broker keeps its pending row 15 min, plus 60 s for its code. */
    public const STATE_TTL = 1200;

    public const KEYS = [
        'live' => ['accountId' => 'MONEI_ACCOUNT_ID', 'apiKey' => 'MONEI_API_KEY', 'signingSecret' => 'MONEI_SIGNING_SECRET'],
        'test' => ['accountId' => 'MONEI_TEST_ACCOUNT_ID', 'apiKey' => 'MONEI_TEST_API_KEY', 'signingSecret' => 'MONEI_TEST_SIGNING_SECRET'],
    ];

    /** @var ConfigurationStoreInterface */
    private $store;
    /** @var callable(): int */
    private $clock;
    /** @var callable(string, string, string, string, string): array The signature of Connect::exchange */
    private $exchange;
    /** @var callable(string): int HTTP status of the test call with a key, 0 when no answer came */
    private $probe;
    /** @var string */
    private $baseUrl;

    public function __construct(ConfigurationStoreInterface $store, callable $clock, callable $exchange, callable $probe, string $baseUrl = Connect::BASE_URL)
    {
        $this->store = $store;
        $this->clock = $clock;
        $this->exchange = $exchange;
        $this->probe = $probe;
        $this->baseUrl = $baseUrl;
    }

    /**
     * Store a new state for the shop scope and return the broker URL. Entries of
     * abandoned starts are deleted first.
     *
     * @param string $settingsUrl Where the result page sends the merchant back: a
     *                            front route cannot build a back-office link
     */
    public function begin(string $redirectUri, int $idShopGroup, int $idShop, string $settingsUrl = ''): string
    {
        $now = ($this->clock)();
        foreach ($this->store->globalNamesWithPrefix(self::STATE_PREFIX) as $name) {
            if (!$this->isFresh($this->entry($name), $now)) {
                $this->store->deleteGlobal($name);
            }
        }

        $start = Connect::start(self::CLIENT, $redirectUri, $this->baseUrl);
        $this->store->setGlobal(self::STATE_PREFIX . $start['state'], (string) json_encode([
            'verifier' => $start['verifier'],
            'redirectUri' => $redirectUri,
            'scope' => ['idShopGroup' => $idShopGroup, 'idShop' => $idShop],
            'settingsUrl' => $settingsUrl,
            'createdAt' => $now,
        ]));

        return $start['url'];
    }

    /**
     * Handle the callback. The result is stored for the shop scope of the state.
     *
     * @return array{switched: array<string, array{accountId: string, apiKey: string, signingSecret: string}>, problems: list<array{mode: string|null, code: string}>, scope: array{idShopGroup: int, idShop: int}|null, settingsUrl: string}
     */
    public function complete(string $state, string $code): array
    {
        if ($code === '') {
            return self::failed('missingCode');
        }

        $name = self::STATE_PREFIX . $state;
        $entry = preg_match('/^[A-Za-z0-9_-]{43}\z/', $state) ? $this->entry($name) : null;
        // Of two callbacks with one state, only the one whose delete removed the entry goes on.
        if ($entry === null || !$this->store->deleteGlobal($name)) {
            return self::failed('unknownState');
        }
        if (!$this->isFresh($entry, ($this->clock)())) {
            return self::failed('expiredState');
        }

        $scope = [
            'idShopGroup' => (int) ($entry['scope']['idShopGroup'] ?? 0),
            'idShop' => (int) ($entry['scope']['idShop'] ?? 0),
        ];

        $settingsUrl = (string) ($entry['settingsUrl'] ?? '');

        try {
            $result = ($this->exchange)($code, (string) $entry['verifier'], self::CLIENT, (string) $entry['redirectUri'], $this->baseUrl);
        } catch (ConnectException $e) {
            return ['settingsUrl' => $settingsUrl] + self::failed($e->getError(), $scope);
        }

        return $this->swap($result, $scope['idShopGroup'], $scope['idShop']) + ['scope' => $scope, 'settingsUrl' => $settingsUrl];
    }

    /**
     * Clear the credentials of each connected mode (mk_), and every pending state. A
     * manual pk_ mode keeps its keys. The keys stay active at MONEI until the merchant
     * revokes them.
     */
    public function disconnect(int $idShopGroup, int $idShop): void
    {
        // A connect started before the disconnect must not finish after it.
        foreach ($this->store->globalNamesWithPrefix(self::STATE_PREFIX) as $name) {
            $this->store->deleteGlobal($name);
        }

        foreach ($this->apiKeys($idShopGroup, $idShop) as $mode => $apiKey) {
            if (strpos($apiKey, 'mk_') !== 0) {
                continue;
            }
            foreach (self::KEYS[$mode] as $key) {
                // An empty value, never a delete: a shop without its own row would
                // read the credentials of its group or of the whole installation.
                $this->store->set($key, '', $idShopGroup, $idShop);
            }
        }
    }

    /**
     * Whether a mode stores a scoped key (mk_) from a connect. Disconnect shows only then.
     */
    public function isConnected(int $idShopGroup, int $idShop): bool
    {
        foreach ($this->apiKeys($idShopGroup, $idShop) as $key) {
            if (strpos($key, 'mk_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{live: string, test: string} The stored API key of each mode
     */
    public function apiKeys(int $idShopGroup, int $idShop): array
    {
        return [
            'live' => $this->store->get(self::KEYS['live']['apiKey'], $idShopGroup, $idShop),
            'test' => $this->store->get(self::KEYS['test']['apiKey'], $idShopGroup, $idShop),
        ];
    }

    /**
     * The fingerprint of a stored key, which the settings form carries from render
     * to save.
     */
    public static function keyFingerprint(string $apiKey): string
    {
        return hash('sha256', $apiKey);
    }

    /**
     * The modes whose stored key changed since the settings form was rendered, such
     * as by a connect in another tab. A save of such a mode would write the old key
     * back.
     *
     * A mode without a posted fingerprint is not checked: a form rendered before the
     * fingerprints existed would otherwise lose its keys and mode on every save.
     *
     * @param array<string, string> $renderedFingerprints keyFingerprint() of each mode at render time, as posted
     *
     * @return string[]
     */
    public function staleModes(array $renderedFingerprints, int $idShopGroup, int $idShop): array
    {
        $stale = [];
        foreach ($this->apiKeys($idShopGroup, $idShop) as $mode => $apiKey) {
            if (isset($renderedFingerprints[$mode]) && !hash_equals(self::keyFingerprint($apiKey), (string) $renderedFingerprints[$mode])) {
                $stale[] = $mode;
            }
        }

        return $stale;
    }

    /**
     * After a manual save: a mode whose key changed loses its signing secret, which
     * belongs to the old key.
     *
     * @param array{live: string, test: string} $keysBefore apiKeys() before the save
     */
    public function forgetSecretsOfChangedKeys(array $keysBefore, int $idShopGroup, int $idShop): void
    {
        foreach ($this->apiKeys($idShopGroup, $idShop) as $mode => $key) {
            if ($key !== $keysBefore[$mode]) {
                $this->store->set(self::KEYS[$mode]['signingSecret'], '', $idShopGroup, $idShop);
            }
        }
    }

    private function swap(array $result, int $idShopGroup, int $idShop): array
    {
        $problems = [];
        foreach ($result['errors'] ?? [] as $mode => $error) {
            $problems[] = ['mode' => $mode, 'code' => $error];
        }

        $liveAccount = $this->store->get(self::KEYS['live']['accountId'], $idShopGroup, $idShop);
        // Open orders of the old account need its key, and a shop split across two merchants is wrong.
        if (isset($result['live']) && $liveAccount !== '' && $result['live']['accountId'] !== $liveAccount) {
            // Only a connected shop has a Disconnect button; a manual shop clears its Live fields instead.
            $problems[] = ['mode' => null, 'code' => $this->isConnected($idShopGroup, $idShop) ? 'otherAccount' : 'otherAccountManual'];

            return ['switched' => [], 'problems' => $problems];
        }

        $switched = [];
        foreach (self::KEYS as $mode => $names) {
            if (!isset($result[$mode])) {
                continue;
            }
            $keys = $result[$mode];
            // /allowed-payment-methods answers 400 for a mode without an enabled provider,
            // so a 400 proves the key as a 2xx does. Any other answer proves nothing.
            $status = ($this->probe)($keys['apiKey']);
            if ($status !== 400 && ($status < 200 || $status > 299)) {
                $problems[] = ['mode' => $mode, 'code' => in_array($status, [401, 403], true) ? 'keyRejected' : 'temporarily_unavailable'];

                continue;
            }
            $this->store->set($names['accountId'], $keys['accountId'], $idShopGroup, $idShop);
            $this->store->set($names['apiKey'], $keys['apiKey'], $idShopGroup, $idShop);
            $this->store->set($names['signingSecret'], $keys['signingSecret'], $idShopGroup, $idShop);
            $switched[$mode] = $keys;
        }

        // A live shop is never switched to test mode.
        if ($switched && $this->store->get(self::KEYS['live']['apiKey'], $idShopGroup, $idShop) === '') {
            $this->store->set('MONEI_PRODUCTION_MODE', '0', $idShopGroup, $idShop);
        }

        return ['switched' => $switched, 'problems' => $problems];
    }

    private function entry(string $name): ?array
    {
        $value = $this->store->getGlobal($name);
        $entry = $value === null ? null : json_decode($value, true);

        return is_array($entry) ? $entry : null;
    }

    private function isFresh(?array $entry, int $now): bool
    {
        return $entry !== null && isset($entry['createdAt']) && is_int($entry['createdAt']) && $now - $entry['createdAt'] < self::STATE_TTL;
    }

    private static function failed(string $code, ?array $scope = null): array
    {
        return ['switched' => [], 'problems' => [['mode' => null, 'code' => $code]], 'scope' => $scope, 'settingsUrl' => ''];
    }
}
