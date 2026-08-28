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
const {
    getConfig,
    getOrderState,
    latestOrderId,
    orderStateId,
    paymentForOrder,
    setConfig,
} = require('../utils/ps-cli');
const { setOrderStateViaBackOffice } = require('../utils/admin');

/**
 * Automatic capture of a pre-authorization.
 *
 * The WooCommerce equivalent of this hook was registered for admin requests only,
 * so an order moved by a shipping module, an ERP sync, cron or the webservice API
 * never captured: the money stayed authorized until it expired while the order
 * read as paid.
 *
 * ⚠️ That property is covered by the hook being registered unconditionally, which
 * upgrade.spec.js asserts, and by there being no admin check in the handler. It is
 * not re-proved here: a bare PrestaShop bootstrap has no Symfony container, so the
 * module's services cannot be built and a CLI driven change fails for reasons
 * unrelated to the code. What this spec covers is the behaviour that a status
 * change captures at all, and that it leaves the merchant's chosen status alone.
 */
test.describe('automatic capture', () => {
    test.afterAll(() => {
        setConfig('MONEI_PAYMENT_ACTION', 'sale');
        setConfig('MONEI_CAPTURE_STATUS', '');
    });

    test('captures on a status change and keeps the new status', async ({ page }) => {
        const shipped = orderStateId('PS_OS_SHIPPING');

        setConfig('MONEI_PAYMENT_ACTION', 'auth');
        setConfig('MONEI_CAPTURE_STATUS', shipped);

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);
        await expect(page.locator(COMPONENT_FRAME.card)).toBeVisible({ timeout: 30000 });
        await fillCard(page, CARDS.direct);
        await placeOrder(page);
        await completeThreeDs(page);
        await expectOrderConfirmation(page);

        const orderId = latestOrderId();
        const authorized = paymentForOrder(orderId);

        expect(authorized.status, 'the payment should be authorized, not charged').toBe('AUTHORIZED');
        expect(authorized.captured).toBe(false);

        await setOrderStateViaBackOffice(page, orderId, 'Shipped');

        const captured = paymentForOrder(orderId);

        expect(captured.captured, 'the status change should have captured the payment').toBe(true);
        expect(captured.status).toBe('SUCCEEDED');

        // ⚠️ The merchant chose Shipped. The manual capture button also writes
        // "Payment accepted", which from a status hook would silently undo the
        // transition that triggered it.
        expect(getOrderState(orderId), 'the chosen status must survive the capture').toBe(shipped);
    });

    test('does not capture when no trigger status is configured', async ({ page }) => {
        setConfig('MONEI_PAYMENT_ACTION', 'auth');
        setConfig('MONEI_CAPTURE_STATUS', '');

        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);
        await expect(page.locator(COMPONENT_FRAME.card)).toBeVisible({ timeout: 30000 });
        await fillCard(page, CARDS.direct);
        await placeOrder(page);
        await completeThreeDs(page);
        await expectOrderConfirmation(page);

        const orderId = latestOrderId();

        expect(getConfig('MONEI_CAPTURE_STATUS')).toBe('');

        await setOrderStateViaBackOffice(page, orderId, 'Shipped');

        expect(
            paymentForOrder(orderId).captured,
            'automatic capture is off by default and must stay off'
        ).toBe(false);
    });
});
