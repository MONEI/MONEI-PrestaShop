const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { goToPaymentStep } = require('../utils/checkout');
const { getConfig, mysql, psEval, setConfig } = require('../utils/ps-cli');

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

const VARIABLE_PRODUCT = fixture(
    'variableProductPath',
    'MONEI_E2E_VARIABLE_PATH',
    '/1-hummingbird-printed-t-shirt.html'
);

/**
 * Express checkout: where the buttons appear, and where they must not.
 *
 * Chromium only ever offers PayPal here — Apple Pay needs Safari on capable
 * hardware and Google Pay is gated by the browser too — so PayPal is what these
 * assert against.
 */
const enableExpress = (locations = 'product,cart') => {
    setConfig('MONEI_EXPRESS_ENABLED', '1');
    setConfig('MONEI_EXPRESS_LOCATIONS', locations);
    setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
    setConfig('MONEI_ALLOW_PAYPAL', '1');
    setConfig('MONEI_ALLOW_GOOGLE', '1');
    setConfig('MONEI_ALLOW_APPLE', '1');
};

test.describe('express checkout', () => {
    test.afterAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart');
        // Restored here, not inline after the assertion that disables it: a failed
        // assertion would otherwise leave PayPal off for every later spec.
        setConfig('MONEI_ALLOW_PAYPAL', '1');
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

    // Regression: with the theme's JS cache on (the PrestaShop 9.2 demo), express.js
    // was bundled ahead of monei.js, because the module registered its scripts with
    // `attribute` instead of `attributes` and so lost `defer`. It bailed out and no
    // button ever mounted, with no error shown.
    test('mounts with the theme JS cache on', async ({ page }) => {
        enableExpress();
        const jsCache = getConfig('PS_JS_THEME_CACHE');
        setConfig('PS_JS_THEME_CACHE', '1');
        // A bundle built earlier keeps its URL; clearing gives this run a fresh one.
        psEval('Media::clearCache();');

        try {
            await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

            await expect(page.locator('script[src*="/assets/cache/"]').first()).toBeAttached();
            // Deferred and outside the bundle: the bundle runs before remote scripts.
            await expect(
                page.locator('script[src*="/modules/monei/views/js/front/express.js"][defer]')
            ).toBeAttached();
            await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });
        } finally {
            setConfig('PS_JS_THEME_CACHE', jsCache);
        }
    });

    // Regression: express read the combination from a form field that no stock
    // theme renders, so every variable product went to the express cart with
    // combination 0, whatever the buyer picked, with no error shown. The button is
    // priced and the cart filled from the same posted fields and the same server
    // resolution, so the priced combination is the one that goes in the cart.
    test('prices the combination the buyer picked', async ({ page }) => {
        enableExpress();

        await page.goto(VARIABLE_PRODUCT, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });

        // Size M, the second option: not the default S.
        const size = page.locator('select[name="group[1]"]');
        const sizeM = await size.locator('option').nth(1).getAttribute('value');

        const remounted = page.waitForResponse(
            (response) =>
                response.url().includes('module/monei/express') &&
                (response.request().postData() || '').includes('"getSelectedProductData"')
        );
        await size.selectOption(sizeM);
        const response = await remounted;
        const { productAttributeId } = await response.json();

        expect(JSON.parse(response.request().postData()).productId).toBe(
            Number(fixture('variableProductId', 'MONEI_E2E_VARIABLE_ID', '1'))
        );

        // The combination must be one that carries size M, read from the catalogue
        // rather than from the page the client read it from.
        const hasSizeM = mysql(
            `SELECT COUNT(*) FROM ps_product_attribute_combination WHERE id_product_attribute = ${Number(productAttributeId)} AND id_attribute = ${Number(sizeM)};`
        ).trim();

        expect(hasSizeM, `combination ${productAttributeId} should be size ${sizeM}`).toBe('1');
    });

    // Regression: a theme without the details block (or with it renamed) sent
    // combination 0 and the line went into the express cart with no combination.
    test('keeps the combination on a theme without the details block', async ({ page }) => {
        enableExpress();

        await page.goto(VARIABLE_PRODUCT, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });

        // What such a theme renders: no element carrying data-product at all.
        await page.evaluate(() =>
            document.querySelectorAll('[data-product]').forEach((element) => {
                element.removeAttribute('data-product');
                element.classList.remove('js-product-details');
                element.removeAttribute('id');
            })
        );

        const size = page.locator('select[name="group[1]"]');
        const sizeM = await size.locator('option').nth(1).getAttribute('value');

        const remounted = page.waitForResponse(
            (response) =>
                response.url().includes('module/monei/express') &&
                (response.request().postData() || '').includes('"getSelectedProductData"')
        );
        await size.selectOption(sizeM);
        const { productAttributeId: combination } = await (await remounted).json();
        const hasSizeM = mysql(
            `SELECT COUNT(*) FROM ps_product_attribute_combination WHERE id_product_attribute = ${Number(combination)} AND id_attribute = ${Number(sizeM)};`
        ).trim();

        expect(hasSizeM, `combination ${combination} should be size ${sizeM}`).toBe('1');
    });

    // Regression: classic renders a quickview's hook in the modal footer, outside
    // the quickview's form, and the block read the page's form behind it instead:
    // the page product's size and quantity were charged. Hummingbird has no
    // quickview on a product page, so the test builds classic's markup.
    test('a quickview block reads the quickview’s form, not the page behind it', async ({
        page,
    }) => {
        enableExpress();

        await page.goto(VARIABLE_PRODUCT, { waitUntil: 'domcontentloaded' });
        await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });

        const size = page.locator('select[name="group[1]"]').first();
        const options = await size
            .locator('option')
            .evaluateAll((list) => list.map((option) => option.value));
        const [pageSize, quickviewSize] = [options[1], options[2]];

        await page.evaluate(
            ({ productId, sizeValue }) => {
                const modal = document.createElement('div');
                modal.className = 'quickview';
                modal.innerHTML = `
                    <form id="add-to-cart-or-refresh">
                        <select name="group[1]"><option value="${sizeValue}" selected></option></select>
                        <input name="qty" value="2">
                    </form>
                    <div class="modal-footer">
                        <div data-monei-express data-location="product" data-product-id="${productId}">
                            <div data-monei-express-label hidden></div>
                            <div data-monei-express-method="paypal"></div>
                            <div data-monei-express-error role="alert"></div>
                        </div>
                    </div>`;
                document.body.appendChild(modal);
            },
            {
                productId: await page
                    .locator('[data-monei-express]')
                    .first()
                    .getAttribute('data-product-id'),
                sizeValue: quickviewSize,
            }
        );

        const posted = [];
        page.on('request', (request) => {
            const body = request.postData() || '';

            if (
                request.url().includes('module/monei/express') &&
                body.includes('"getSelectedProductData"')
            ) {
                posted.push(JSON.parse(body));
            }
        });

        // Any product change remounts every product container, the quickview's too.
        await size.selectOption(pageSize);

        await expect
            .poll(() => posted.find((data) => Number(data.quantity) === 2), { timeout: 30000 })
            .toBeTruthy();
        const quickview = posted.find((data) => Number(data.quantity) === 2);

        expect(String(quickview.groups['1'])).toBe(quickviewSize);
    });

    // Regression: a product that cannot be bought (out of stock, below the
    // minimum) showed "could not be added" at page load, and scrolled to it,
    // before the shopper had clicked anything.
    test('an out-of-stock product shows no block and no error', async ({ page }) => {
        enableExpress();
        const id = Number(fixture('simpleProductId', 'MONEI_E2E_PRODUCT_ID', '6'));
        const quantity = psEval(`echo (int) StockAvailable::getQuantityAvailableByProduct(${id});`);
        const outOfStock = psEval(`echo (int) StockAvailable::outOfStock(${id});`);
        const orderOutOfStock = getConfig('PS_ORDER_OUT_OF_STOCK');

        psEval(
            `StockAvailable::setQuantity(${id}, 0, 0); StockAvailable::setProductOutOfStock(${id}, 0);`
        );
        setConfig('PS_ORDER_OUT_OF_STOCK', '0');

        try {
            const mounted = page.waitForResponse(
                (response) =>
                    response.url().includes('module/monei/express') &&
                    (response.request().postData() || '').includes('"getSelectedProductData"')
            );
            await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
            expect((await mounted).ok(), 'the button should not be priced').toBe(false);

            await expect(page.locator('[data-monei-express]')).toBeHidden();
            await expect(page.locator('[data-monei-express-error]')).toHaveText('');
        } finally {
            psEval(
                `StockAvailable::setQuantity(${id}, 0, ${Number(quantity)}); StockAvailable::setProductOutOfStock(${id}, ${Number(outOfStock)});`
            );
            setConfig('PS_ORDER_OUT_OF_STOCK', orderOutOfStock);
        }
    });

    // Regression: the hidden block's slots were removed, so picking a combination
    // that is in stock had nothing to remount into until a page reload. Stock
    // themes hide this: core.js re-renders the additional-info block on every
    // change. A theme that renders the hook outside that block keeps the old one.
    test('appears when the shopper picks a combination in stock after one that is not', async ({
        page,
    }) => {
        enableExpress();
        const id = Number(fixture('variableProductId', 'MONEI_E2E_VARIABLE_ID', '1'));
        const combination = Number(
            mysql(
                `SELECT id_product_attribute FROM ps_product_attribute WHERE id_product = ${id} AND default_on = 1;`
            ).trim()
        );
        const quantity = psEval(
            `echo (int) StockAvailable::getQuantityAvailableByProduct(${id}, ${combination});`
        );
        const outOfStock = psEval(`echo (int) StockAvailable::outOfStock(${id});`);
        const orderOutOfStock = getConfig('PS_ORDER_OUT_OF_STOCK');

        psEval(
            `StockAvailable::setQuantity(${id}, ${combination}, 0); StockAvailable::setProductOutOfStock(${id}, 0);`
        );
        setConfig('PS_ORDER_OUT_OF_STOCK', '0');

        try {
            const mounted = page.waitForResponse(
                (response) =>
                    response.url().includes('module/monei/express') &&
                    (response.request().postData() || '').includes('"getSelectedProductData"')
            );
            await page.goto(VARIABLE_PRODUCT, { waitUntil: 'domcontentloaded' });
            expect((await mounted).ok(), 'the default combination should be refused').toBe(false);
            await expect(page.locator('[data-monei-express]')).toBeHidden();

            // Such a theme: core.js finds no additional-info block to replace.
            await page.evaluate(() => {
                window.prestashop.selectors.product.additionalInfos = '.no-such-block';
            });

            const size = page.locator('select[name="group[1]"]');
            const current = await size.inputValue();
            const other = await size
                .locator(`option:not([value="${current}"])`)
                .first()
                .getAttribute('value');

            await size.selectOption(other);

            await expect(page.locator(PAYPAL_BUTTON)).toBeVisible({ timeout: 60000 });
        } finally {
            psEval(
                `StockAvailable::setQuantity(${id}, ${combination}, ${Number(quantity)}); StockAvailable::setProductOutOfStock(${id}, ${Number(outOfStock)});`
            );
            setConfig('PS_ORDER_OUT_OF_STOCK', orderOutOfStock);
        }
    });

    // Regression: the sheet was never repriced. The mount total carried no
    // shipping, and createOrder picked its own carrier, so a shopper could approve
    // one total and be charged another. monei.js is stubbed: the wallet sheet
    // itself cannot be driven from a test.
    test('the wallet reprices shipping, and its carrier and total reach createOrder', async ({
        page,
    }) => {
        enableExpress();

        await page.route('https://js.monei.com/v3/monei.js', (route) =>
            route.fulfill({
                contentType: 'application/javascript',
                body: `window.monei = {
                    PayPal: (props) => ({ render: () => { window.moneiPayPal = props; props.onLoad(true); } }),
                    PaymentRequest: (props) => ({ render: () => props.onLoad(false) }),
                    confirmPayment: async () => ({}),
                };`,
            })
        );

        let createOrder = null;
        const after = [];

        await page.route('**/module/monei/express**', (route) => {
            const body = JSON.parse(route.request().postData() || '{}');

            if (createOrder) {
                after.push(body);
            }

            if (body.action !== 'createOrder') {
                return route.continue();
            }

            createOrder = body;

            // What the server answers when the cart total differs from the sheet's,
            // after signing the wallet's guest in.
            return route.fulfill({
                status: 409,
                contentType: 'application/json',
                body: JSON.stringify({
                    ok: false,
                    code: 'amount_changed',
                    message: 'The total changed',
                    token: 'rotated',
                }),
            });
        });

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => window.moneiPayPal);

        const opened = await page.evaluate(() =>
            window.moneiPayPal.onShippingAddressChange({
                country: 'FR',
                city: 'Paris',
                zip: '75001',
            })
        );
        const dearer = opened.shippingOptions.find((option) => !option.selected);

        expect(dearer, 'the shop should offer a second carrier to France').toBeTruthy();

        const repriced = await page.evaluate(
            (option) => window.moneiPayPal.onShippingOptionChange(option),
            dearer
        );

        expect(repriced.amount).toBeGreaterThan(opened.amount);

        await page.evaluate(
            (option) =>
                window.moneiPayPal.onSubmit({
                    token: 'stub',
                    paymentMethod: 'paypal',
                    shippingOption: option,
                    shippingDetails: {
                        name: 'Ada Lovelace',
                        email: 'ada@example.com',
                        address: {
                            line1: '1 Rue de Rivoli',
                            city: 'Paris',
                            zip: '75001',
                            country: 'FR',
                        },
                    },
                }),
            dearer
        );

        await expect.poll(() => createOrder).toBeTruthy();
        expect(createOrder.carrierId).toBe(dearer.id);
        expect(createOrder.amount).toBe(repriced.amount);

        // The button is priced again from the express cart, with the new token.
        await expect
            .poll(() => after.find((body) => body.action === 'getCartDetails'))
            .toBeTruthy();
        const repricing = after.find((body) => body.action === 'getCartDetails');

        expect(repricing.expressCartId).toBe(createOrder.expressCartId);
        expect(after.every((body) => body.token === 'rotated')).toBe(true);
        expect(createOrder.expressCartId).toBeGreaterThan(0);
    });

    test('is absent at the checkout payment step', async ({ page }) => {
        enableExpress('product,cart,checkout');

        await goToPaymentStep(page);

        // Express belongs on product and cart, where it collapses the funnel. By
        // the payment step the shopper has already entered email, address and
        // carrier, so the block does not render here — even if a stale config
        // still lists 'checkout', the hook that drew it is gone — and it no longer
        // duplicates the ordinary wallet options on that page.
        await expect(page.locator('[data-monei-express]')).toHaveCount(0);
    });

    test('collapses instead of showing a label over nothing', async ({ page }) => {
        enableExpress();

        // Make every wallet report itself unsupported by taking PayPal away and
        // opening in a context where the SDK cannot offer the others.
        await page.addInitScript(() => {
            // Chromium would offer Google Pay; deny the API the SDK probes.
            Object.defineProperty(window, 'PaymentRequest', {
                value: undefined,
                configurable: true,
            });
        });
        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay');

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        const container = page.locator('[data-monei-express]');

        // Either the SDK reports unsupported and the block collapses, or it never
        // reports and the label stays hidden. Both are acceptable; a visible
        // label over an empty slot is not.
        await expect(async () => {
            const hidden = await container.evaluate((el) => el.hidden);
            const labelHidden = await container
                .locator('[data-monei-express-label]')
                .evaluate((el) => el.hidden);

            expect(hidden || labelHidden).toBe(true);
        }).toPass({ timeout: 30000 });

        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
    });

    test('is absent everywhere when express is switched off', async ({ page }) => {
        setConfig('MONEI_EXPRESS_ENABLED', '');

        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        await expect(page.locator('[data-monei-express]')).toHaveCount(0);
        // The client must not be shipped either.
        expect(await page.locator('script[src*="express.js"]').count()).toBe(0);
    });

    test('is absent on a location the merchant did not choose', async ({ page }) => {
        enableExpress('cart');

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
            // Runs in the page, not in Node. Announce a product change the way
            // the theme does: the client then discards its memoised express cart
            // and mounts again, which is the real path that reprices a button —
            // and the one that now hits the routed failure.
            window.prestashop.emit('updatedProduct', {});
        });

        await expect(page.locator('[data-monei-express-error]')).toHaveText(/declined/i, {
            timeout: 30000,
        });
    });
});
