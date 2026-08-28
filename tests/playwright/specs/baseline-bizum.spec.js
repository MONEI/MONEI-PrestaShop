const { test, expect } = require('../utils/test');
const {
    BIZUM_PHONE,
    COMPONENT_FRAME,
    acceptTerms,
    goToPaymentStep,
    selectPaymentOption,
} = require('../utils/checkout');

/**
 * Bizum half of the pre-refactor regression net for Task 4.
 *
 * ⚠️ Stops at the hand off to MONEI rather than at an order confirmation, and that
 * is not a gap in the module. Submitting a Bizum payment comes back E650, which
 * MONEI maps from the Redsys response BIZ00202, "Functionality not yet
 * implemented". The module created the payment and MONEI routed it correctly; the
 * acquirer's test environment does not implement the Bizum REST flow, so no test
 * can complete one.
 *
 * Asserting that error here would tie the suite to an acquirer's test setup and
 * break the day it gains the capability. What is asserted instead is everything
 * the module is responsible for.
 *
 * What is asserted is what Task 4 can actually break: the module mounts the
 * component, gates it on the terms checkbox, opens the phone dialog, and carries
 * the submission through to a MONEI response.
 */
test.describe('baseline: bizum', () => {
    test('bizum component is gated on the terms checkbox', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /bizum/i);

        await expect(page.locator(COMPONENT_FRAME.bizum)).toBeVisible({ timeout: 30000 });
        await page.frameLocator(COMPONENT_FRAME.bizum).locator('#bizum_button').click();

        // `onBeforeOpen` returns false while the required terms checkbox is
        // unchecked, so the dialog must not appear.
        await expect(page.locator(COMPONENT_FRAME.bizumDialog)).toHaveCount(0);
    });

    test('bizum opens its phone dialog and hands off to MONEI', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /bizum/i);
        await acceptTerms(page);

        await page.frameLocator(COMPONENT_FRAME.bizum).locator('#bizum_button').click();

        const dialog = page.frameLocator(COMPONENT_FRAME.bizumDialog);

        await dialog.getByTestId('bizum-phone-input').fill(BIZUM_PHONE);
        await dialog.getByTestId('bizum-pay-button').click();

        // Either MONEI redirects the shopper onward, or the module surfaces the
        // response. Both prove the wiring carried the submission through; a broken
        // extraction would leave the page inert instead.
        await expect
            .poll(
                async () =>
                    /order-confirmation/.test(page.url()) ||
                    (await page.locator('.alert-danger, .js-monei-error').count()) > 0,
                { timeout: 90000, message: 'bizum submission produced no response' }
            )
            .toBe(true);
    });
});
