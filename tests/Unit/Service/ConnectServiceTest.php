<?php

declare(strict_types=1);

namespace PsMonei\Tests\Unit\Service;

use Monei\ConnectException;
use PHPUnit\Framework\TestCase;
use PsMonei\Service\Monei\ConnectService;
use PsMonei\Tests\Unit\Service\Fake\InMemoryConfigurationStore;

/**
 * ConnectService: the single-use state, the test call of each new key, and the swap
 * of credentials. Every test names the bug it catches.
 *
 * The shop scope is 0/0 unless a test says otherwise: a store without multistore,
 * where Configuration writes the row of no shop.
 */
class ConnectServiceTest extends TestCase
{
    private const NOW = 1700000000;
    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REDIRECT = 'https://shop.example/module/monei/connect';
    private const BROKER = 'https://oauth.example';

    /** @var array[] */
    private $exchanges = [];
    /** @var string[] */
    private $probed = [];
    /** @var array|ConnectException */
    private $exchangeAnswer = [];
    /** @var array<string, int> */
    private $statuses = [];

    private function service(InMemoryConfigurationStore $store): ConnectService
    {
        return new ConnectService(
            $store,
            static function () {
                return self::NOW;
            },
            function (...$args) {
                $this->exchanges[] = $args;
                if ($this->exchangeAnswer instanceof ConnectException) {
                    throw $this->exchangeAnswer;
                }

                return $this->exchangeAnswer;
            },
            function (string $apiKey) {
                $this->probed[] = $apiKey;

                return $this->statuses[$apiKey] ?? 200;
            },
            self::BROKER
        );
    }

    private static function entry(int $createdAt, int $idShopGroup = 0, int $idShop = 0): string
    {
        return (string) json_encode([
            'verifier' => 'the-verifier',
            'redirectUri' => self::REDIRECT,
            'scope' => ['idShopGroup' => $idShopGroup, 'idShop' => $idShop],
            'createdAt' => $createdAt,
        ]);
    }

    /**
     * A shop with manual pk_ credentials in both modes, in live mode, and a fresh state entry.
     */
    private static function manualShop(array $extra = []): InMemoryConfigurationStore
    {
        $store = new InMemoryConfigurationStore();
        $values = $extra + [
            'MONEI_PRODUCTION_MODE' => '1',
            'MONEI_ACCOUNT_ID' => 'acc-live',
            'MONEI_API_KEY' => 'pk_live_old',
            'MONEI_TEST_ACCOUNT_ID' => 'acc-test-old',
            'MONEI_TEST_API_KEY' => 'pk_test_old',
        ];
        foreach ($values as $key => $value) {
            $store->set($key, $value, 0, 0);
        }
        $store->setGlobal(ConnectService::STATE_PREFIX . self::STATE, self::entry(self::NOW - 60));

        return $store;
    }

    private static function keys(string $mode, string $account): array
    {
        return ['accountId' => $account, 'apiKey' => "mk_{$mode}_new", 'signingSecret' => "mksec_{$mode}_new"];
    }

    private static function credentials(InMemoryConfigurationStore $store, int $idShopGroup = 0, int $idShop = 0): array
    {
        $credentials = [];
        foreach (['MONEI_PRODUCTION_MODE', 'MONEI_ACCOUNT_ID', 'MONEI_API_KEY', 'MONEI_SIGNING_SECRET', 'MONEI_TEST_ACCOUNT_ID', 'MONEI_TEST_API_KEY', 'MONEI_TEST_SIGNING_SECRET'] as $key) {
            $credentials[$key] = $store->get($key, $idShopGroup, $idShop);
        }

        return $credentials;
    }

    private static function codes(array $result): array
    {
        return array_map(static function ($problem) {
            return [$problem['mode'], $problem['code']];
        }, $result['problems']);
    }

    /**
     * Bug: a callback code is accepted without the stored state, after 20 min, as a
     * second callback that lost the delete race, or through a state that is not ours
     * (the shape check guards the configuration name).
     *
     * @dataProvider refusedStates
     */
    public function testAStateThatIsNotStoredAndFreshReachesNoExchange(string $state, ?string $storedEntry, array $lostDeletes, string $code): void
    {
        $store = self::manualShop();
        if ($storedEntry !== null) {
            $store->setGlobal(ConnectService::STATE_PREFIX . $state, $storedEntry);
        }
        $store->lostDeletes = $lostDeletes;
        $before = self::credentials($store);

        $result = $this->service($store)->complete($state, 'the-code');

        $this->assertSame([], $this->exchanges);
        $this->assertSame([[null, $code]], self::codes($result));
        $this->assertSame($before, self::credentials($store));
    }

    public function refusedStates(): array
    {
        return [
            'unknown' => [str_repeat('b', 43), null, [], 'unknownState'],
            'not our shape' => ['x', self::entry(self::NOW - 60), [], 'unknownState'],
            '20 min old' => [self::STATE, self::entry(self::NOW - 1200), [], 'expiredState'],
            'lost the race' => [self::STATE, null, [ConnectService::STATE_PREFIX . self::STATE], 'unknownState'],
        ];
    }

    /**
     * Bug: a state is accepted twice, so a replayed callback URL exchanges again. Also
     * pins the exchange arguments: another verifier or redirect URI than the start
     * sent is always invalid_grant.
     */
    public function testAStateIsSingleUse(): void
    {
        $store = self::manualShop();
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-old')];
        $service = $this->service($store);

        $service->complete(self::STATE, 'the-code');
        $second = $service->complete(self::STATE, 'the-code');

        $this->assertSame([['the-code', 'the-verifier', 'prestashop', self::REDIRECT, self::BROKER]], $this->exchanges);
        $this->assertSame([[null, 'unknownState']], self::codes($second));
        $this->assertArrayNotHasKey(ConnectService::STATE_PREFIX . self::STATE, $store->globals);
    }

    /**
     * Bug: a request without code spends the merchant's state, so the real callback
     * that follows is refused.
     */
    public function testACallbackWithoutCodeKeepsTheStateAndChangesNothing(): void
    {
        $store = self::manualShop();
        $before = [$store->values, $store->globals];

        $result = $this->service($store)->complete(self::STATE, '');

        $this->assertSame([], $this->exchanges);
        $this->assertSame([[null, 'missingCode']], self::codes($result));
        $this->assertSame($before, [$store->values, $store->globals]);
    }

    /**
     * Bug: abandoned starts leave state rows behind for ever, a start deletes the entry
     * of a connect still in progress in another tab, or the entry loses the shop that
     * started the flow.
     */
    public function testAStartDeletesEntriesOlderThan20MinutesAndKeepsAYoungerOne(): void
    {
        $old = ConnectService::STATE_PREFIX . str_repeat('o', 43);
        $young = ConnectService::STATE_PREFIX . str_repeat('y', 43);
        $store = new InMemoryConfigurationStore();
        $store->setGlobal($old, self::entry(self::NOW - 1200));
        $store->setGlobal($young, self::entry(self::NOW - 1199));

        $url = $this->service($store)->begin(self::REDIRECT, 1, 2);

        $this->assertArrayNotHasKey($old, $store->globals);
        $this->assertArrayHasKey($young, $store->globals);
        $this->assertStringStartsWith('https://oauth.example/integrations/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $entry = json_decode($store->globals[ConnectService::STATE_PREFIX . $query['state']], true);
        $this->assertSame(self::REDIRECT, $entry['redirectUri']);
        $this->assertSame(['idShopGroup' => 1, 'idShop' => 2], $entry['scope']);
        $this->assertSame(self::NOW, $entry['createdAt']);
        $this->assertSame(64, strlen($entry['verifier']));
    }

    /**
     * Bug: a failed exchange wipes or half-writes working manual credentials.
     */
    public function testAFailedExchangeChangesNothing(): void
    {
        $store = self::manualShop();
        $before = self::credentials($store);
        $this->exchangeAnswer = new ConnectException('access_denied', 403);

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame([[null, 'access_denied']], self::codes($result));
        $this->assertSame($before, self::credentials($store));
    }

    /**
     * Bug: a 200 that refuses both modes (cap, not_approved) changes credentials or the
     * mode, or hides a refusal code. The shop has no Live key, so a mode switch without
     * a switched key shows here. The texts of the codes are pinned in
     * ConnectWiringTest: monei.php holds the translations.
     */
    public function testA200WithOnlyErrorsChangesNothingAndReportsEachCode(): void
    {
        $store = self::manualShop(['MONEI_ACCOUNT_ID' => '', 'MONEI_API_KEY' => '']);
        $before = self::credentials($store);
        $this->exchangeAnswer = ['errors' => ['live' => 'not_approved', 'test' => 'cap']];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame([['live', 'not_approved'], ['test', 'cap']], self::codes($result));
        $this->assertSame([], $result['switched']);
        $this->assertSame($before, self::credentials($store));
    }

    /**
     * Bug: the test call goes out with the stored key (or a cached client), so a broken
     * new key replaces a working one.
     */
    public function testTheTestCallUsesEachNewKey(): void
    {
        $this->exchangeAnswer = ['live' => self::keys('live', 'acc-live'), 'test' => self::keys('test', 'acc-test-new')];

        $this->service(self::manualShop())->complete(self::STATE, 'the-code');

        $this->assertSame(['mk_live_new', 'mk_test_new'], $this->probed);
    }

    /**
     * Bug: a key the edge refuses, or a test call MONEI did not answer, replaces
     * working credentials; or a 400 (a mode with no enabled provider) is taken for a
     * refused key and blocks the connect. A refusal and a missing answer say different
     * things to the merchant.
     *
     * @dataProvider callAnswers
     */
    public function testTheTestCallAnswerDecidesTheSwitch(int $status, array $expectedTest, array $expectedCodes): void
    {
        $store = self::manualShop();
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-new')];
        $this->statuses = ['mk_test_new' => $status];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $credentials = self::credentials($store);
        $this->assertSame($expectedTest, [$credentials['MONEI_TEST_ACCOUNT_ID'], $credentials['MONEI_TEST_API_KEY'], $credentials['MONEI_TEST_SIGNING_SECRET']]);
        $this->assertSame($expectedCodes, self::codes($result));
    }

    public function callAnswers(): array
    {
        $new = ['acc-test-new', 'mk_test_new', 'mksec_test_new'];
        $kept = ['acc-test-old', 'pk_test_old', ''];

        return [
            '400 proves the key' => [400, $new, []],
            '401' => [401, $kept, [['test', 'keyRejected']]],
            '403' => [403, $kept, [['test', 'keyRejected']]],
            '500' => [500, $kept, [['test', 'temporarily_unavailable']]],
            '429' => [429, $kept, [['test', 'temporarily_unavailable']]],
            'no answer' => [0, $kept, [['test', 'temporarily_unavailable']]],
        ];
    }

    /**
     * Bug: one refused mode drops the other, or flips the shop's mode.
     */
    public function testTestProvenAndLiveRefusedSwitchesTestOnly(): void
    {
        $store = self::manualShop();
        $this->exchangeAnswer = ['live' => self::keys('live', 'acc-live'), 'test' => self::keys('test', 'acc-test-new')];
        $this->statuses = ['mk_live_new' => 401];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame([
            'MONEI_PRODUCTION_MODE' => '1',
            'MONEI_ACCOUNT_ID' => 'acc-live',
            'MONEI_API_KEY' => 'pk_live_old',
            'MONEI_SIGNING_SECRET' => '',
            'MONEI_TEST_ACCOUNT_ID' => 'acc-test-new',
            'MONEI_TEST_API_KEY' => 'mk_test_new',
            'MONEI_TEST_SIGNING_SECRET' => 'mksec_test_new',
        ], self::credentials($store));
        $this->assertSame([['live', 'keyRejected']], self::codes($result));
    }

    /**
     * Bug: a live shop is switched to test mode, or loses its manual Live keys, when
     * only Test comes back.
     */
    public function testOnlyTestOnAShopWithManualLiveKeepsLiveAndTheMode(): void
    {
        $store = self::manualShop();
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-new')];

        $this->service($store)->complete(self::STATE, 'the-code');

        $credentials = self::credentials($store);
        $this->assertSame(['1', 'acc-live', 'pk_live_old'], [$credentials['MONEI_PRODUCTION_MODE'], $credentials['MONEI_ACCOUNT_ID'], $credentials['MONEI_API_KEY']]);
        $this->assertSame('mk_test_new', $credentials['MONEI_TEST_API_KEY']);
    }

    /**
     * Bug: a shop with no Live credentials stays in live mode after it gets only a Test
     * key (not_approved), so every payment fails.
     */
    public function testOnlyTestOnAShopWithoutLiveTurnsTestModeOn(): void
    {
        $store = new InMemoryConfigurationStore();
        $store->set('MONEI_PRODUCTION_MODE', '1', 0, 0);
        $store->setGlobal(ConnectService::STATE_PREFIX . self::STATE, self::entry(self::NOW - 60));
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-new'), 'errors' => ['live' => 'not_approved']];

        $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame('0', $store->get('MONEI_PRODUCTION_MODE', 0, 0));
        $this->assertSame('mk_test_new', $store->get('MONEI_TEST_API_KEY', 0, 0));
    }

    /**
     * Bug: a shop is split across two MONEI accounts: open orders of the old Live
     * account lose their key. The Test result is refused too, even proven. A manual
     * shop has no Disconnect button, so it must not be told to disconnect.
     *
     * @dataProvider storedLiveKeys
     */
    public function testAnotherLiveAccountSwitchesNeitherMode(string $liveKey, string $code): void
    {
        $store = self::manualShop(['MONEI_API_KEY' => $liveKey]);
        $before = self::credentials($store);
        $this->exchangeAnswer = ['live' => self::keys('live', 'acc-other'), 'test' => self::keys('test', 'acc-test-new')];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame($before, self::credentials($store));
        $this->assertSame([[null, $code]], self::codes($result));
        $this->assertSame([], $this->probed);
    }

    public function storedLiveKeys(): array
    {
        return [
            'manual pk_ key' => ['pk_live_old', 'otherAccountManual'],
            'connected' => ['mk_live_old', 'otherAccount'],
        ];
    }

    /**
     * Bug: a new Test account id is refused. The 2026-10-22 repair gave many live
     * accounts a new test account, and the token answer does not say which live
     * account a test account belongs to.
     */
    public function testOnlyTestWithANewTestAccountSwitchesTest(): void
    {
        $store = self::manualShop();
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-new')];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame(['test'], array_keys($result['switched']));
        $this->assertSame('acc-test-new', $store->get('MONEI_TEST_ACCOUNT_ID', 0, 0));
    }

    /**
     * Bug: a connect started on one shop of a multistore install writes the
     * credentials of another shop, or of every shop.
     */
    public function testTheResultIsStoredForTheShopThatStartedTheFlow(): void
    {
        $store = self::manualShop();
        $store->setGlobal(ConnectService::STATE_PREFIX . self::STATE, self::entry(self::NOW - 60, 1, 2));
        $this->exchangeAnswer = ['test' => self::keys('test', 'acc-test-new')];

        $result = $this->service($store)->complete(self::STATE, 'the-code');

        $this->assertSame(['idShopGroup' => 1, 'idShop' => 2], $result['scope']);
        $this->assertSame('mk_test_new', $store->get('MONEI_TEST_API_KEY', 1, 2));
        $this->assertSame('pk_test_old', $store->get('MONEI_TEST_API_KEY', 0, 0));
        $this->assertSame('pk_test_old', $store->get('MONEI_TEST_API_KEY', 1, 3));
    }

    /**
     * Bug: after a manual key change, v2 callbacks still verify with the secret of the
     * replaced key; or a save that leaves the key alone drops the secret of a connected
     * mode.
     */
    public function testASaveClearsTheSecretOfAChangedKeyOnly(): void
    {
        $store = new InMemoryConfigurationStore();
        foreach (['MONEI_API_KEY' => 'mk_live_a', 'MONEI_SIGNING_SECRET' => 'mksec_live_a', 'MONEI_TEST_API_KEY' => 'mk_test_a', 'MONEI_TEST_SIGNING_SECRET' => 'mksec_test_a'] as $key => $value) {
            $store->set($key, $value, 0, 0);
        }
        $service = $this->service($store);
        $before = $service->apiKeys(0, 0);
        $store->set('MONEI_TEST_API_KEY', 'pk_test_manual', 0, 0);

        $service->forgetSecretsOfChangedKeys($before, 0, 0);

        $this->assertSame('mksec_live_a', $store->get('MONEI_SIGNING_SECRET', 0, 0));
        $this->assertSame('', $store->get('MONEI_TEST_SIGNING_SECRET', 0, 0));
    }

    /**
     * Bug: a disconnect on one shop deletes its rows, and the shop then reads the
     * credentials of the whole installation (possibly another account) instead of
     * none; or it clears a manual pk_ mode, which the merchant typed in and the
     * connect never touched; or a connect started before it finishes after it.
     */
    public function testDisconnectEmptiesOnlyTheConnectedModesAtTheShop(): void
    {
        $store = self::manualShop();
        foreach (['MONEI_ACCOUNT_ID' => 'acc-shop', 'MONEI_API_KEY' => 'mk_live_shop', 'MONEI_SIGNING_SECRET' => 'mksec_live_shop', 'MONEI_TEST_ACCOUNT_ID' => 'acc-test-shop', 'MONEI_TEST_API_KEY' => 'pk_test_shop'] as $key => $value) {
            $store->set($key, $value, 1, 2);
        }

        $this->service($store)->disconnect(1, 2);

        $this->assertSame([], $store->globalNamesWithPrefix(ConnectService::STATE_PREFIX), 'a pending connect must not finish after the disconnect');
        $shop = self::credentials($store, 1, 2);
        $this->assertSame(['', '', ''], [$shop['MONEI_ACCOUNT_ID'], $shop['MONEI_API_KEY'], $shop['MONEI_SIGNING_SECRET']]);
        $this->assertSame(['acc-test-shop', 'pk_test_shop'], [$shop['MONEI_TEST_ACCOUNT_ID'], $shop['MONEI_TEST_API_KEY']]);
        $this->assertSame('pk_live_old', $store->get('MONEI_API_KEY', 0, 0));
    }

    /**
     * Bug: a settings tab left open while the merchant connects in another tab saves
     * the old pk_ key back, and the secret rule then deletes the new signing secret.
     */
    public function testAModeWhoseKeyChangedSinceTheFormRenderedIsStale(): void
    {
        $store = self::manualShop();
        $rendered = [
            'live' => ConnectService::keyFingerprint('pk_live_old'),
            'test' => ConnectService::keyFingerprint('pk_test_old'),
        ];
        $service = $this->service($store);
        $before = $service->staleModes($rendered, 0, 0);
        $store->set('MONEI_TEST_API_KEY', 'mk_test_new', 0, 0);

        $this->assertSame([[], ['test']], [$before, $service->staleModes($rendered, 0, 0)]);
        $this->assertSame(['live', 'test'], $service->staleModes([], 0, 0));
    }
}
