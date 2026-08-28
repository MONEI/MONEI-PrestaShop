const { test, expect } = require('../utils/test');
const { baseUrl, supportsThreeDs } = require('../utils/env');
const { getConfig, installedVersion, isHookRegistered } = require('../utils/ps-cli');

/**
 * Proves the harness itself works before any spec relies on it: the browser can
 * reach the store, and the container fixtures can read the store back.
 *
 * A failure here means the environment is wrong, not the module.
 */
test.describe('harness', () => {
    test('storefront responds', async ({ page }) => {
        const response = await page.goto('/');

        expect(response.status()).toBeLessThan(400);
        await expect(page.locator('body')).toBeVisible();
    });

    test('module is installed and reachable through the container', () => {
        const version = installedVersion();

        expect(version, 'monei module should be installed in the container').not.toBe('');
    });

    test('store is in MONEI test mode', () => {
        // Every spec takes a payment. A store left in production mode would take
        // real ones, so this is asserted rather than assumed.
        expect(getConfig('MONEI_PRODUCTION_MODE')).not.toBe('1');
    });

    test('module hooks are registered', () => {
        expect(isHookRegistered('paymentOptions')).toBe(true);
        expect(isHookRegistered('displayPaymentByBinaries')).toBe(true);
    });

    test('reports whether this run can complete 3D Secure', () => {
        // Not an assertion: it records which half of the suite this environment
        // can run, so a skipped card spec later is traceable to the base URL.
        console.log(
            `base URL ${baseUrl()} — 3DS ${supportsThreeDs() ? 'supported' : 'NOT supported, card specs will skip'}`
        );
        expect(baseUrl()).toMatch(/^https?:\/\//);
    });
});
