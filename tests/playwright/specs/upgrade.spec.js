const { test, expect } = require('../utils/test');
const {
    deleteConfig,
    getConfig,
    installedVersion,
    isHookRegistered,
    setInstalledVersion,
    unregisterHook,
    upgradeModule,
} = require('../utils/ps-cli');

/**
 * Proves `upgrade/upgrade-2.1.0.php` actually reaches an existing merchant.
 *
 * ⚠️ A fresh install runs `install()` and never touches `upgrade/`, so hooks and
 * defaults added only to `install()` pass every fresh-install check and still
 * reach nobody who upgrades. This replays the upgrade against a store rewound to
 * the previous release.
 */
const NEW_HOOKS = [
    'actionOrderStatusPostUpdate',
    'displayProductAdditionalInfo',
    'displayExpressCheckout',
    'displayPaymentTop',
];

const NEW_DEFAULTS = {
    MONEI_CARD_LAYOUT: 'split',
    MONEI_EXPRESS_LOCATIONS: 'product,cart,checkout',
    MONEI_EXPRESS_METHODS: 'applePay,googlePay,paypal',
};

test.describe('upgrade to 2.1.0', () => {
    test('registers the new hooks and seeds the new defaults', async () => {
        // Rewind to the state a 2.0.18 merchant is in.
        setInstalledVersion('2.0.18');
        NEW_HOOKS.forEach(unregisterHook);
        Object.keys(NEW_DEFAULTS).forEach(deleteConfig);
        deleteConfig('MONEI_EXPRESS_ENABLED');
        deleteConfig('MONEI_CAPTURE_STATUS');

        expect(installedVersion()).toBe('2.0.18');
        NEW_HOOKS.forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should start detached`).toBe(false)
        );

        upgradeModule();

        expect(installedVersion()).toBe('2.1.0');

        NEW_HOOKS.forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should be registered by the upgrade`).toBe(true)
        );

        Object.entries(NEW_DEFAULTS).forEach(([key, value]) =>
            expect(getConfig(key), `${key} should be seeded by the upgrade`).toBe(value)
        );

        // Express stays off on upgrade: it changes the storefront, so a merchant
        // opts in rather than finding new buttons after an update.
        expect(getConfig('MONEI_EXPRESS_ENABLED')).toBe('');
        // Automatic capture likewise stays off until a merchant picks statuses.
        expect(getConfig('MONEI_CAPTURE_STATUS')).toBe('');
    });
});
