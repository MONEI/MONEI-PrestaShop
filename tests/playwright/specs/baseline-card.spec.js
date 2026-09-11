const { test, expect } = require('../utils/test');
const {
    CARDS,
    MOUNT,
    expectCardFieldsReady,
    completeThreeDs,
    expectOrderConfirmation,
    fillCard,
    goToPaymentStep,
    placeOrder,
    selectPaymentOption,
} = require('../utils/checkout');

/**
 * Regression net for the JavaScript extraction in Task 4.
 *
 * These run against the module as it is today, with the payment JavaScript still
 * inline in `views/templates/hook/displayPaymentByBinaries.tpl`. Extracting it is
 * a behavioural rewrite of the dispatch between five payment methods, so it needs
 * specs that were green beforehand for "provably inert" to mean anything.
 */
test.describe('baseline: card', () => {
    test('MONEI payment options render at checkout', async ({ page }) => {
        await goToPaymentStep(page);

        const options = page.locator('.payment-option');
        await expect(options.first()).toBeVisible({ timeout: 30000 });

        const labels = (await page.locator('#checkout-payment-step').innerText()).toLowerCase();

        expect(labels).toContain('card');
        expect(labels).toContain('bizum');
        expect(labels).toContain('paypal');
    });

    test('each MONEI component mounts its container', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        // The five init functions are what Task 4's extraction rewrites. A mounted
        // container is the observable proof each one ran.
        await expect(page.locator(MOUNT.card)).toBeAttached({ timeout: 30000 });
        await expect(page.locator(MOUNT.bizum)).toBeAttached();
        await expect(page.locator(MOUNT.paypal)).toBeAttached();

        // Layout aware: split is the default from 2.1.0, so the card fields are
        // three iframes rather than one. card-layout.spec.js pins each layout
        // specifically; this only cares that the card component mounted at all.
        await expectCardFieldsReady(page);
    });

    test('card payment completes and reaches order confirmation', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        await expectCardFieldsReady(page);
        await fillCard(page, CARDS.direct);
        await placeOrder(page);
        await completeThreeDs(page);

        const { details, reference } = await expectOrderConfirmation(page);

        expect(reference, 'order confirmation should carry a reference').not.toBe('');
        // Proves MONEI actually processed the card, rather than the order merely
        // being created and the confirmation page rendering.
        expect(details).toMatch(/4414/);
    });
});
