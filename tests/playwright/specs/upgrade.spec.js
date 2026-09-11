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

const SEEDED_DEFAULTS = {
    MONEI_CARD_LAYOUT: 'single',
    // 2.1.0 seeds product,cart,checkout; 2.1.1 migrates it back off checkout.
    MONEI_EXPRESS_LOCATIONS: 'product,cart',
    MONEI_EXPRESS_METHODS: 'applePay,googlePay,paypal',
};

test.describe('upgrade to 2.1.1', () => {
    test('attaches the surviving hooks, drops the payment-step one, seeds the defaults', async () => {
        // Rewind to the state a 2.0.18 merchant is in.
        setInstalledVersion('2.0.18');
        [...REGISTERED_HOOKS, REMOVED_HOOK].forEach(unregisterHook);
        Object.keys(SEEDED_DEFAULTS).forEach(deleteConfig);
        deleteConfig('MONEI_EXPRESS_ENABLED');
        deleteConfig('MONEI_CAPTURE_STATUS');

        expect(installedVersion()).toBe('2.0.18');
        [...REGISTERED_HOOKS, REMOVED_HOOK].forEach((hook) =>
            expect(isHookRegistered(hook), `${hook} should start detached`).toBe(false)
        );

        upgradeModule();

        expect(installedVersion()).toBe('2.1.1');

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
