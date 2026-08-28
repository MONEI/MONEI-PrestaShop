const { test, expect } = require('../utils/test');
const {
    COMPONENT_FRAME,
    expectOrderConfirmation,
    goToPaymentStep,
    payWithPayPal,
    selectPaymentOption,
} = require('../utils/checkout');

/**
 * PayPal half of the pre-refactor regression net for Task 4.
 *
 * Sandbox accounts are the ones published at https://docs.monei.com/testing and
 * used by MONEI's own suite. Never invent new ones.
 */
test.describe('baseline: paypal', () => {
    test('paypal button renders inside the MONEI component', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /paypal/i);

        // ⚠️ The MONEI wrapper iframe is a zero height container: it reports a
        // width but no height, so a visibility assertion on it fails while the
        // component is working perfectly. The button PayPal renders inside it is
        // the thing with a box, and the thing a shopper can actually click.
        await expect(page.locator(COMPONENT_FRAME.paypal)).toBeAttached({ timeout: 30000 });
        await expect(page.locator(COMPONENT_FRAME.paypalButton)).toBeVisible({ timeout: 60000 });
    });

    test('paypal payment completes and reaches order confirmation', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /paypal/i);
        await expect(page.locator(COMPONENT_FRAME.paypalButton)).toBeVisible({ timeout: 60000 });

        await payWithPayPal(page);

        const { reference } = await expectOrderConfirmation(page);

        expect(reference, 'order confirmation should carry a reference').not.toBe('');
    });
});
