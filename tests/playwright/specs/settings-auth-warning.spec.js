const { test, expect } = require('../utils/test');
const { openModuleConfiguration } = require('../utils/admin');
const { setConfig } = require('../utils/ps-cli');

/**
 * Pre-authorization removes MB WAY and Multibanco from the storefront rather than
 * charging them immediately. That is deliberate, but it used to happen silently,
 * so a merchant lost two payment methods with nothing to explain it.
 */
test.describe('settings: pre-authorization hides some methods', () => {
    test.afterAll(() => {
        setConfig('MONEI_PAYMENT_ACTION', 'sale');
    });

    test('warns when auth mode is hiding an enabled method', async ({ page }) => {
        setConfig('MONEI_ALLOW_MBWAY', '1');
        setConfig('MONEI_PAYMENT_ACTION', 'auth');

        await openModuleConfiguration(page);

        // ⚠️ Read the alert elements, not body innerText. The configuration forms
        // sit inside collapsed panels, so innerText omits them entirely and the
        // assertion fails on content that is present and correct.
        const alerts = (await page.locator('.alert').allInnerTexts()).join(' ');

        expect(alerts).toMatch(/currently hidden from your checkout/i);
        expect(alerts).toMatch(/MB WAY/i);
    });

    test('says nothing when sale mode hides nothing', async ({ page }) => {
        setConfig('MONEI_ALLOW_MBWAY', '1');
        setConfig('MONEI_PAYMENT_ACTION', 'sale');

        await openModuleConfiguration(page);

        const alerts = (await page.locator('.alert').allInnerTexts()).join(' ');

        expect(alerts).not.toMatch(/currently hidden from your checkout/i);
    });
});
