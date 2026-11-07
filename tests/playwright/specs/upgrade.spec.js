const fs = require('fs');
const path = require('path');
const { test, expect } = require('../utils/test');
const {
    deleteConfig,
    getConfig,
    installedVersion,
    isHookRegistered,
    mysql,
    psEval,
    setInstalledVersion,
    unregisterHook,
    upgradeModule,
} = require('../utils/ps-cli');

/**
 * Proves the upgrade/ scripts actually reach an existing merchant.
 *
 * ⚠️ A fresh install runs install() and never touches upgrade/, so hooks and
 * defaults added only to install() pass every fresh-install check and still
 * reach nobody who upgrades. This replays the upgrade against a store rewound to
 * an earlier release and drives it through the whole chain to the current code.
 */

// Attached by the 2.1.0 upgrade and kept.
const REGISTERED_HOOKS = [
    'actionOrderStatusPostUpdate',
    'displayProductAdditionalInfo',
    'displayExpressCheckout',
];

// Attached by 2.1.0, then detached again by 2.1.1 along with the payment-step
// express block it drew.
const REMOVED_HOOK = 'displayPaymentTop';

// Attached by 2.2.0: they delete the MONEI customer with the PrestaShop one.
const CUSTOMER_HOOKS = ['actionObjectCustomerDeleteAfter', 'actionObjectCustomerUpdateAfter'];

const customerTableExists = () => mysql("SHOW TABLES LIKE 'ps_monei2_customer';") !== '';

const cardExpirationType = () =>
    mysql(
        'SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ' +
            "AND TABLE_NAME = 'ps_monei2_customer_card' AND COLUMN_NAME = 'expiration';"
    );

const SEEDED_DEFAULTS = {
    MONEI_CARD_LAYOUT: 'single',
    // 2.1.0 seeds product,cart,checkout; 2.1.1 migrates it back off checkout.
    MONEI_EXPRESS_LOCATIONS: 'product,cart',
    MONEI_EXPRESS_METHODS: 'applePay,googlePay,paypal',
};

test.describe('upgrade to 2.2.0', () => {
    test('attaches the surviving hooks, drops the payment-step one, seeds the defaults', async () => {
        // Rewind to the state a 2.0.18 merchant is in.
        setInstalledVersion('2.0.18');
        [...REGISTERED_HOOKS, REMOVED_HOOK, ...CUSTOMER_HOOKS].forEach(unregisterHook);
        // Only the 2.2.0 script creates it for an existing store.
        mysql('DROP TABLE IF EXISTS ps_monei2_customer;');
        // Earlier releases store the card expiry in a signed INT, which ends in 2038,
        // so a card saved past it by another spec cannot exist in that store.
        mysql('DELETE FROM ps_monei2_customer_card WHERE expiration > 2147483647;');
        mysql('ALTER TABLE ps_monei2_customer_card MODIFY expiration INT(11) NOT NULL;');
        Object.keys(SEEDED_DEFAULTS).forEach(deleteConfig);
        deleteConfig('MONEI_EXPRESS_ENABLED');
        deleteConfig('MONEI_CAPTURE_STATUS');

        expect(installedVersion()).toBe('2.0.18');
        [...REGISTERED_HOOKS, REMOVED_HOOK].forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should start detached`).toBe(false)
        );

        // Files deployed before the upgrade ran: a missing table must read as an
        // error, never as "no mapping", or every checkout creates a MONEI customer.
        expect(
            psEval(
                "Module::getInstanceByName('monei'); try { (new \\PsMonei\\Repository\\CustomerMappingStore())->findMapping(1, 'acc', 'test'); echo 'read'; } catch (\\Throwable $e) { echo 'threw'; }"
            )
        ).toBe('threw');

        upgradeModule();

        // PrestaShop records the version of the files on disk, not of the last script.
        const filesVersion = fs
            .readFileSync(path.resolve(__dirname, '../../../monei.php'), 'utf8')
            .match(/const VERSION = '([^']+)'/)[1];

        expect(installedVersion()).toBe(filesVersion);
        expect(customerTableExists(), 'monei2_customer should be created by the upgrade').toBe(
            true
        );
        CUSTOMER_HOOKS.forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should be registered by the upgrade`).toBe(true)
        );
        expect(cardExpirationType(), 'the upgrade should widen the card expiry').toBe('bigint');

        REGISTERED_HOOKS.forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should be registered by the upgrade`).toBe(true)
        );

        // Express is gone from the payment step, so its hook must not survive the
        // upgrade: 2.1.0 attaches it and 2.1.1 detaches it again.
        expect(
            isHookRegistered(REMOVED_HOOK),
            `${REMOVED_HOOK} should be removed by the 2.1.1 upgrade`
        ).toBe(false);

        Object.entries(SEEDED_DEFAULTS).forEach(([key, value]) =>
            expect(getConfig(key), `${key} should be seeded by the upgrade`).toBe(value)
        );

        // Express stays off on upgrade: it changes the storefront, so a merchant
        // opts in rather than finding new buttons after an update.
        expect(getConfig('MONEI_EXPRESS_ENABLED')).toBe('');
        // Automatic capture likewise stays off until a merchant picks statuses.
        expect(getConfig('MONEI_CAPTURE_STATUS')).toBe('');
    });
});
