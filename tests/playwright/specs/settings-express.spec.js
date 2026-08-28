const { test, expect } = require('../utils/test');
const { openModuleConfiguration } = require('../utils/admin');
const { getConfig, setConfig } = require('../utils/ps-cli');

/**
 * The express settings are multiple selects, which post arrays. They are stored
 * as comma separated lists, so saving is where that conversion either works or
 * silently writes "Array".
 */
test.describe('settings: express checkout', () => {
    test.afterAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart,checkout');
        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
    });

    test('saves the locations and methods as a comma separated list', async ({ page }) => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
        setConfig('MONEI_EXPRESS_LOCATIONS', '');
        setConfig('MONEI_EXPRESS_METHODS', '');

        await openModuleConfiguration(page);
        await page.locator('a[href="#panel-conf-5"]').click();

        const form = page.locator('#panel-conf-5');

        await form.locator('input[name="MONEI_EXPRESS_ENABLED"][value="1"]').check();
        await form
            .locator('select[name="MONEI_EXPRESS_LOCATIONS[]"]')
            .selectOption(['product', 'cart']);
        await form
            .locator('select[name="MONEI_EXPRESS_METHODS[]"]')
            .selectOption(['applePay', 'paypal']);
        await form.locator('button[name="submitMoneiModuleExpress"]').click();
        await page.waitForLoadState('networkidle');

        expect(getConfig('MONEI_EXPRESS_ENABLED')).toBe('1');
        expect(getConfig('MONEI_EXPRESS_LOCATIONS')).toBe('product,cart');
        expect(getConfig('MONEI_EXPRESS_METHODS')).toBe('applePay,paypal');
    });

    test('keeps the split card layout as the default and can be switched back', async ({
        page,
    }) => {
        expect(getConfig('MONEI_CARD_LAYOUT'), 'split is the 2.1.0 default').toBe('split');

        await openModuleConfiguration(page);
        await page.locator('a[href="#panel-conf-4"]').click();

        const form = page.locator('#panel-conf-4');

        await form.locator('select[name="MONEI_CARD_LAYOUT"]').selectOption('single');
        await form.locator('button[name="submitMoneiModuleComponentStyle"]').click();
        await page.waitForLoadState('networkidle');

        expect(getConfig('MONEI_CARD_LAYOUT'), 'a merchant must be able to revert').toBe('single');

        setConfig('MONEI_CARD_LAYOUT', 'split');
    });
});
