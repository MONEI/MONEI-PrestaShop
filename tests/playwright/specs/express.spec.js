const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { goToPaymentStep } = require('../utils/checkout');
const { setConfig } = require('../utils/ps-cli');

/**
 * ⚠️ PayPal renders a component frame and a prerender frame, both titled "PayPal",
 * so an unscoped selector trips Playwright's strict mode on a healthy page.
 */
const PAYPAL_BUTTON = '[data-monei-express-method="paypal"] iframe[title="PayPal"] >> nth=0';

const PRODUCT = fixture(
    'simpleProductPath',
    'MONEI_E2E_PRODUCT_PATH',
    '/6-mug-the-best-is-yet-to-come.html'
);

/**
 * Express checkout: where the buttons appear, and where they must not.
 *
 * Chromium only ever offers PayPal here — Apple Pay needs Safari on capable
 * hardware and Google Pay is gated by the browser too — so PayPal is what these
 * assert against.
 */
const enableExpress = (locations = 'product,cart,checkout') => {
    setConfig('MONEI_EXPRESS_ENABLED', '1');
    setConfig('MONEI_EXPRESS_LOCATIONS', locations);
    setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
    setConfig('MONEI_ALLOW_PAYPAL', '1');
};

test.describe('express checkout', () => {
    test.afterAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart,checkout');
    });

    test('renders on the product page and mounts a button', async ({ page }) => {
        enableExpress();

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        const container = page.locator('[data-monei-express]');

        await expect(container).toHaveCount(1);
        await expect(page.locator('[data-monei-express-method="paypal"]')).toHaveCount(1);
        await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });

        // Nothing went wrong, so the error region stays empty.
        await expect(page.locator('[data-monei-express-error]')).toHaveText('');
    });

    test('renders above the payment options at checkout', async ({ page }) => {
        enableExpress();

        await goToPaymentStep(page);

        await expect(page.locator('[data-monei-express]')).toHaveCount(1);
    });

    test('is absent everywhere when express is switched off', async ({ page }) => {
        setConfig('MONEI_EXPRESS_ENABLED', '');

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('[data-monei-express]')).toHaveCount(0);
        // The client must not be shipped either.
        expect(await page.locator('script[src*="express.js"]').count()).toBe(0);
    });

    test('is absent on a location the merchant did not choose', async ({ page }) => {
        enableExpress('cart,checkout');

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('[data-monei-express]')).toHaveCount(0);
    });

    test('does not offer a method that is disabled as a payment method', async ({ page }) => {
        // Express settings widen nothing: turning PayPal off under Payment methods
        // must remove the express PayPal button too.
        enableExpress();
        setConfig('MONEI_ALLOW_PAYPAL', '');

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('[data-monei-express-method="paypal"]')).toHaveCount(0);

        setConfig('MONEI_ALLOW_PAYPAL', '1');
    });

    test('reports a failure on the container the payment started from', async ({ page }) => {
        enableExpress();

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });

        // Make the next express call fail the way a rejected order would.
        await page.route('**/module/monei/express**', (route) =>
            route.fulfill({
                status: 400,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: false,
                    code: 'declined',
                    message: 'Payment was declined',
                }),
            })
        );

        // ⚠️ The rule this exists for: a failure must be visible on the surface the
        // shopper started from. The WooCommerce equivalent discarded a rejected
        // express order in silence, leaving the shopper on a page that had already
        // taken their wallet approval.
        await page.evaluate(() => {
            // Runs in the page, not in Node: re-arm the container and replay the
            // event the client mounts on, so the routed failure is exercised.
            const container = document.querySelector('[data-monei-express]');
            container.dataset.moneiMounted = '';
            document.dispatchEvent(new Event('DOMContentLoaded'));
        });

        await expect(page.locator('[data-monei-express-error]')).toHaveText(/declined/i, {
            timeout: 30000,
        });
    });
});
