const { test, expect } = require('../utils/test');
const {
    CARDS,
    COMPONENT_FRAME,
    completeThreeDs,
    expectOrderConfirmation,
    fillCard,
    goToPaymentStep,
    placeOrder,
    selectPaymentOption,
} = require('../utils/checkout');
const { setConfig } = require('../utils/ps-cli');

/**
 * Split card fields are the default from 2.1.0. Single line stays available,
 * because the change alters the appearance of every existing merchant's checkout
 * and they must be able to put it back.
 */
test.describe('card field layout', () => {
    test.afterAll(() => {
        setConfig('MONEI_CARD_LAYOUT', 'split');
    });

    test('split renders three separate fields', async ({ page }) => {
        setConfig('MONEI_CARD_LAYOUT', 'split');

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        await expect(page.locator('#monei-card-number')).toBeVisible({ timeout: 30000 });
        await expect(page.locator('#monei-card-expiry')).toBeVisible();
        await expect(page.locator('#monei-card-cvc')).toBeVisible();

        // The single line container must not be rendered at the same time.
        await expect(page.locator('#monei-card_container')).toHaveCount(0);

        // Each part mounts its own iframe.
        await expect(page.locator('#monei-card-number iframe')).toHaveCount(1);
        await expect(page.locator('#monei-card-expiry iframe')).toHaveCount(1);
        await expect(page.locator('#monei-card-cvc iframe')).toHaveCount(1);
    });

    test('single restores the one line field', async ({ page }) => {
        setConfig('MONEI_CARD_LAYOUT', 'single');

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        await expect(page.locator(COMPONENT_FRAME.card)).toBeVisible({ timeout: 30000 });
        await expect(page.locator('#monei-card-number')).toHaveCount(0);
    });

    test('a payment completes with the split layout', async ({ page }) => {
        // The default from 2.1.0, so this is the path most merchants will be on.
        setConfig('MONEI_CARD_LAYOUT', 'split');

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);
        await expect(page.locator(COMPONENT_FRAME.cardNumber)).toBeVisible({ timeout: 30000 });

        await fillCard(page, CARDS.direct);
        await placeOrder(page);
        await completeThreeDs(page);

        const { details, reference } = await expectOrderConfirmation(page);

        expect(reference).not.toBe('');
        expect(details).toMatch(/4414/);
    });

    test('a payment completes with the single line layout', async ({ page }) => {
        setConfig('MONEI_CARD_LAYOUT', 'single');

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);
        await expect(page.locator(COMPONENT_FRAME.card)).toBeVisible({ timeout: 30000 });

        await fillCard(page, CARDS.direct);
        await placeOrder(page);
        await completeThreeDs(page);

        const { reference } = await expectOrderConfirmation(page);

        expect(reference).not.toBe('');
    });
});
