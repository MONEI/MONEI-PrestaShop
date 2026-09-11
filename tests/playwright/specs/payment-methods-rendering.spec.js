const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { goToPaymentStep, selectPaymentOption } = require('../utils/checkout');
const { setConfig, storeTheme } = require('../utils/ps-cli');

const PRODUCT = fixture(
    'simpleProductPath',
    'MONEI_E2E_PRODUCT_PATH',
    '/6-mug-the-best-is-yet-to-come.html'
);

/**
 * How each MONEI payment method LOOKS, not whether it exists.
 *
 * Three UI regressions reached review with the whole suite green: an express
 * PayPal button that mounted at zero height under its heading, logos rendered
 * at intrinsic size on hummingbird, and split card fields stacked full width
 * and overflowing their column. Every one had passed a test that asserted the
 * element was present. This file asserts what a shopper sees.
 *
 * Shared contract with the WooCommerce and Magento suites, taken from monei-js:
 *   1. geometry first — a container and its iframe must have real size, so a
 *      collapsed component fails with a message that says which one and how;
 *   2. then a screenshot of the method block, never the page, so theme chrome
 *      does not leak into the diff.
 *
 * ⚠️ Baselines are keyed by theme. classic and hummingbird lay the checkout out
 * differently on purpose, so a baseline from one is a false failure on the
 * other. Regenerate with `--update-snapshots` against the store whose theme
 * changed, and commit the result; they are the source of truth for the
 * checkout's appearance.
 *
 * ⚠️ retries 0 here, unlike the payment journeys: a rendering test that needs a
 * retry is a rendering bug.
 */
test.describe.configure({ retries: 0 });

const MIN_HEIGHT = 30;
const MIN_WIDTH = 200;

/**
 * Wait for a MONEI iframe to exist and have laid out, then check its size.
 *
 * Nothing inside the frame is inspected: it is cross-origin, and the point is
 * the box it occupies on the page.
 *
 * @param {import('@playwright/test').Locator} frame - The iframe
 * @param {string} what - Name for the failure message
 */
const expectRealSize = async (frame, what, { minWidth = MIN_WIDTH, srcFrame = frame } = {}) => {
    await expect(frame, `${what} iframe should mount`).toBeAttached({ timeout: 60000 });
    // PayPal nests its own button frame inside MONEI's wrapper; the wrapper is
    // what loads from js.monei.com, so a caller can name it separately.
    await expect(srcFrame, `${what} should load from js.monei.com`).toHaveAttribute(
        'src',
        /js\.monei\.com/,
        { timeout: 60000 }
    );
    // ⚠️ Poll directly on the real threshold, not on "> 0" then a separate
    // read. The SDK iframe passes through 0 height while mounting, so a
    // poll-then-measure races: it can see a transient 0 and fail a field that
    // is about to lay out. Polling to MIN_HEIGHT retries through the mount and
    // still fails a frame that stays collapsed past the timeout.
    await expect
        .poll(async () => (await frame.boundingBox())?.height ?? 0, {
            message: `${what} iframe never reached a real height (collapsed frame)`,
            timeout: 60000,
        })
        .toBeGreaterThanOrEqual(MIN_HEIGHT);

    const box = await frame.boundingBox();

    expect(box.width, `${what} is ${box.width}px wide`).toBeGreaterThanOrEqual(minWidth);
};

test.describe('payment methods: rendering', () => {
    let theme;

    test.beforeAll(() => {
        theme = storeTheme();
        setConfig('MONEI_EXPRESS_ENABLED', '1');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart');
        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
    });

    test.afterAll(() => {
        setConfig('MONEI_CARD_LAYOUT', 'single');
        setConfig('MONEI_EXPRESS_ENABLED', '');
    });

    test.beforeEach(async ({ page }) => {
        // A collapsed viewport reports 0x0 and every bounding box below is
        // meaningless; better to fail here with a reason.
        expect(page.viewportSize().width, 'viewport should be real').toBeGreaterThan(300);

        // ⚠️ Cache-bust every stylesheet. The store is behind a Cloudflare quick
        // tunnel, which caches CSS at a versioned URL (cf-cache-status: HIT, ~4h)
        // — so a real CSS regression can be served stale and the whole suite
        // passes against the old, correct styles. A per-run token on each .css
        // request forces the current file. CSS only: rewriting scripts breaks
        // module loading. Nothing else in the suite would catch this, which is
        // exactly how a broken checkout shipped green before.
        // ⚠️ The module's own CSS only. Rewriting every stylesheet would also
        // touch PayPal's and Google's cross-origin CSS and break their buttons.
        // checkout_page.css and express.css are what this suite protects.
        const cacheBust = `cb=${Date.now()}`;
        await page.route(/modules\/monei\/.*\.css(\?|$)/, (route) => {
            const url = new URL(route.request().url());
            url.searchParams.set('cb', cacheBust);
            route.continue({ url: url.toString() });
        });
    });

    const shot = (name) => `checkout-${name}-${theme}.png`;

    test('card, single line', async ({ page }) => {
        setConfig('MONEI_CARD_LAYOUT', 'single');
        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        const form = page.locator('#payment-form-monei');

        await expectRealSize(form.locator('iframe[title="monei_card_input"]'), 'single card field');
        await expect(form).toHaveScreenshot(shot('card-single'));
    });

    test('card, split fields', async ({ page }, testInfo) => {
        setConfig('MONEI_CARD_LAYOUT', 'split');
        await goToPaymentStep(page);
        await selectPaymentOption(page, /credit card/i);

        const form = page.locator('#payment-form-monei');

        // All three parts, each with real size: the regression was three
        // correctly mounted frames the shopper could not see.
        await expectRealSize(form.locator('iframe[title="monei_card_number"]'), 'card number');
        // Half a row each, so half the width floor; 120 still fits a phone.
        await expectRealSize(form.locator('iframe[title="monei_card_expiry"]'), 'card expiry', {
            minWidth: 100,
        });
        await expectRealSize(form.locator('iframe[title="monei_card_cvc"]'), 'card CVC', {
            minWidth: 100,
        });

        const expiry = await form.locator('#monei-card-expiry').boundingBox();
        const cvc = await form.locator('#monei-card-cvc').boundingBox();
        const number = await form.locator('#monei-card-number').boundingBox();

        // ⚠️ Desktop only. On a phone the two half-fields legitimately stack,
        // which is why the mobile project asserts size and the screenshot but
        // not the row. On desktop they must share a row and stay within the
        // card number's edges — the stacked-full-width bug lived here.
        if (testInfo.project.name === 'chromium') {
            expect(Math.abs(expiry.y - cvc.y), 'expiry and CVC should sit on one row').toBeLessThan(
                2
            );
            expect(
                expiry.x,
                'expiry should not start before the card number'
            ).toBeGreaterThanOrEqual(number.x - 1);
            expect(
                cvc.x + cvc.width,
                'CVC should not end past the card number'
            ).toBeLessThanOrEqual(number.x + number.width + 1);
        }

        // Screenshot on desktop only: the split fields stack on a phone (the row
        // assertion above is desktop-only for the same reason), and the mobile
        // layout reflows enough to make its pixel baseline unstable. Geometry
        // still runs on both.
        if (testInfo.project.name === 'chromium') {
            await expect(form).toHaveScreenshot(shot('card-split'));
        }
    });

    test('bizum', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /bizum/i);

        const block = page.locator('.js-payment-monei-bizum');

        await expectRealSize(block.locator('iframe[title="monei_bizum_button"]'), 'Bizum button');
        await expect(block).toHaveScreenshot(shot('bizum'));
    });

    test('paypal', async ({ page }) => {
        await goToPaymentStep(page);
        await selectPaymentOption(page, /paypal/i);

        const block = page.locator('.js-payment-monei-paypal');

        // PayPal nests its own frame inside MONEI's; the inner one is the button.
        await expectRealSize(block.locator('iframe[title="PayPal"]').first(), 'PayPal button', {
            srcFrame: block.locator('iframe[title="monei_paypal"]'),
        });
        // No screenshot: PayPal renders its button at a height that varies run
        // to run (47-53px), so a pixel baseline flakes. Geometry catches 0px.
    });

    test('express block on the product page', async ({ page }) => {
        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        const block = page.locator('[data-monei-express]');

        await expectRealSize(
            block.locator('iframe[title="monei_payment_request"]'),
            'wallet button'
        );
        await expectRealSize(
            block.locator('iframe[title="PayPal"]').first(),
            'PayPal express button',
            {
                srcFrame: block.locator('iframe[title="monei_paypal"]'),
            }
        );
        // No screenshot: this block holds the non-deterministic PayPal express
        // button (see the paypal test). Both wallet buttons still assert size.
    });

    test('payment option list', async ({ page }) => {
        await goToPaymentStep(page);

        const list = page
            .locator(
                '#checkout-payment-step .payment-options, #checkout-payment-step .payment-options__list'
            )
            .first();

        // Logos at the module's size, not their intrinsic size. The hummingbird
        // regression was every logo at a different height.
        const heights = await list
            .locator('.payment-option img')
            .evaluateAll((imgs) =>
                imgs.map((i) => Math.round(i.getBoundingClientRect().height)).filter((h) => h > 0)
            );

        expect(heights.length, 'payment option logos should render').toBeGreaterThan(0);
        heights.forEach((h) =>
            expect(h, `a payment option logo is ${h}px tall`).toBeLessThanOrEqual(28)
        );

        await expect(list).toHaveScreenshot(shot('options'));
    });
});
