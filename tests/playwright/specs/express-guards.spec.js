const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { console: psConsole, mysql, setConfig } = require('../utils/ps-cli');

const PRODUCT = fixture(
    'simpleProductPath',
    'MONEI_E2E_PRODUCT_PATH',
    '/6-mug-the-best-is-yet-to-come.html'
);

/**
 * The express endpoint takes browser-supplied input on the payment path, so each
 * of these is a boundary that has to hold with a hostile or merely careless
 * client on the other side. All three were reported by review after the first
 * suite was green — because nothing here drove the endpoint with the inputs a
 * real wallet, or a real attacker, would send.
 *
 * Driven through the controller: the behaviour under test is the server's.
 */
test.describe('express checkout: guards on the payment path', () => {
    test.beforeAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '1');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart,checkout');
        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
        setConfig('MONEI_ALLOW_PAYPAL', '1');
    });

    test.afterAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
    });

    const expressConfig = async (page) => {
        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        const config = await page.evaluate(() =>
            typeof window.moneiExpress === 'undefined' ? null : window.moneiExpress
        );

        expect(config, 'express should be published to the product page').not.toBeNull();

        return config;
    };

    const post = async (page, endpoint, data) =>
        (await page.request.post(endpoint, { data })).json();

    /**
     * The shape monei.js v3 actually emits on submit: BillingDetails, with the
     * address nested. Sending the flat shape the first version of the client
     * invented would test a contract no wallet uses — which is how a client that
     * read the wrong fields stayed green.
     */
    const DETAILS = {
        name: 'Ada Lovelace',
        phone: '600000000',
        address: {
            line1: '1 Rue de Rivoli',
            city: 'Paris',
            zip: '75001',
            country: 'FR',
        },
    };

    test('mounting on a product page does not replace the shopper’s basket', async ({ page }) => {
        // A real basket first, through the normal add-to-cart.
        await page.goto('/2-9-brown-bear-printed-sweater.html', { waitUntil: 'domcontentloaded' });
        await page.locator('[data-button-action="add-to-cart"]').first().click();
        await expect(page.locator('#blockcart-modal, .cart-content').first()).toBeVisible({
            timeout: 20000,
        });

        const { endpoint, token } = await expressConfig(page);

        const labels = (details) => details.items.map((item) => item.label);
        const basket = await post(page, endpoint, { action: 'getCartDetails', token });

        expect(basket.ok).toBe(true);
        expect(labels(basket)).toContain('Hummingbird printed sweater');

        // What every express button does on mount.
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        expect(cart.ok).toBe(true);
        expect(cart.expressCartId).toBeGreaterThan(0);
        expect(labels(cart)).toContain('Hummingbird printed t-shirt');

        // ⚠️ The regression: viewing a product page used to swap the session onto
        // the express cart, and with several methods mounted, lost the record of
        // which cart to go back to. The session must still answer with the basket.
        const after = await post(page, endpoint, { action: 'getCartDetails', token });

        expect(labels(after)).toEqual(labels(basket));
        expect(labels(after)).not.toContain('Hummingbird printed t-shirt');
    });

    test('an email that belongs to an account is refused, and the visitor is not signed in', async ({
        page,
    }) => {
        const victim = mysql(
            'SELECT email FROM ps_customer WHERE is_guest = 0 AND active = 1 ORDER BY id_customer LIMIT 1;'
        ).trim();

        expect(victim, 'the store should have a registered account to test against').toBeTruthy();

        const { endpoint, token } = await expressConfig(page);
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        const body = await post(page, endpoint, {
            action: 'createOrder',
            token,
            paymentMethod: 'paypal',
            expressCartId: cart.expressCartId,
            email: victim,
            shippingDetails: DETAILS,
        });

        expect(body.ok).toBe(false);
        expect(body.message).toMatch(/already exists/i);

        // ⚠️ The regression: this used to look the account up by email, reuse it,
        // and write its password hash and logged = 1 into the visitor's cookie —
        // signing an anonymous visitor in as whoever owns the address. The
        // account page must still bounce to login.
        const response = await page.goto('/my-account', { waitUntil: 'domcontentloaded' });

        expect(response.url()).toMatch(/\/login/);
    });

    test('another session’s express cart cannot be paid for', async ({ browser, page }) => {
        const { endpoint, token } = await expressConfig(page);
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        const other = await browser.newContext();
        const thief = await other.newPage();
        const theirs = await expressConfig(thief);

        const body = await post(thief, theirs.endpoint, {
            action: 'createOrder',
            token: theirs.token,
            paymentMethod: 'paypal',
            expressCartId: cart.expressCartId,
            email: `thief-${Date.now()}@example.com`,
            shippingDetails: DETAILS,
        });

        // The id is browser-supplied. Without the ownership check, any visitor
        // could pay for — and learn the contents of — someone else's cart.
        expect(body.ok).toBe(false);
        expect(body.message).toMatch(/not available/i);

        await other.close();
    });

    const statsdataActive = () =>
        mysql("SELECT active FROM ps_module WHERE name = 'statsdata';").trim() === '1';

    const waitForMount = (page) =>
        page.waitForResponse(
            (response) =>
                response.url().includes('module/monei/express') &&
                (response.request().postData() || '').includes('"addToCart"')
        );

    // Regression: PrestaShop gives a visitor a guest id only through the statsdata
    // module. With it off (the PrestaShop demo), the express cart had no owner and
    // every guest express payment failed with "The express cart is not available".
    test('a guest without a guest id can pay, with statsdata off', async ({ browser }) => {
        const statsdataWasOn = statsdataActive();
        psConsole(['prestashop:module', 'disable', 'statsdata']);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();
            const mounted = waitForMount(page);
            const { endpoint, token } = await expressConfig(page);

            // The page's own first express request mints the guest id; the calls
            // below follow it, as a shopper's click would.
            await mounted;

            const cart = await post(page, endpoint, {
                action: 'addToCart',
                token,
                productId: 1,
                productAttributeId: 1,
                quantity: 1,
            });

            const order = await post(page, endpoint, {
                action: 'createOrder',
                token,
                paymentMethod: 'paypal',
                expressCartId: cart.expressCartId,
                email: `noguest-${Date.now()}@example.com`,
                shippingDetails: DETAILS,
            });

            expect(order.ok, JSON.stringify(order)).toBe(true);
        } finally {
            await context.close();

            if (statsdataWasOn) {
                psConsole(['prestashop:module', 'enable', 'statsdata']);
            }
        }
    });

    // Regression: 2.2.2 minted a guest id on every product page render, so every
    // cookieless request (a crawler) inserted a ps_guest row: the growth merchants
    // avoid by turning statsdata off.
    test('a page view that never calls express adds no guest row', async ({ browser }) => {
        const statsdataWasOn = statsdataActive();
        psConsole(['prestashop:module', 'disable', 'statsdata']);
        const context = await browser.newContext();

        try {
            const guests = () => Number(mysql('SELECT COUNT(*) FROM ps_guest;').trim());
            const before = guests();

            // A crawler: the HTML only, no scripts, so express never runs.
            const response = await context.request.get(PRODUCT);

            expect(response.ok()).toBe(true);
            expect(await response.text()).toContain('data-monei-express');
            expect(guests()).toBe(before);
        } finally {
            await context.close();

            if (statsdataWasOn) {
                psConsole(['prestashop:module', 'enable', 'statsdata']);
            }
        }
    });

    // Regression: a theme without the details block the client reads sent
    // combination 0, and the cart line was added with no combination (base price,
    // product-level stock).
    test('a combination the browser could not read is resolved, never left empty', async ({
        page,
    }) => {
        const product = Number(fixture('variableProductId', 'MONEI_E2E_VARIABLE_ID', '1'));
        const defaultCombination = mysql(
            `SELECT id_product_attribute FROM ps_product_attribute WHERE id_product = ${product} AND default_on = 1;`
        ).trim();
        const other = mysql(
            `SELECT id_product_attribute FROM ps_product_attribute WHERE id_product = ${product} AND id_product_attribute <> ${Number(defaultCombination)} ORDER BY id_product_attribute LIMIT 1;`
        ).trim();
        const groups = Object.fromEntries(
            mysql(
                `SELECT a.id_attribute_group, a.id_attribute FROM ps_product_attribute_combination pac JOIN ps_attribute a ON a.id_attribute = pac.id_attribute WHERE pac.id_product_attribute = ${Number(other)};`
            )
                .trim()
                .split('\n')
                .map((row) => row.split(/\s+/))
        );

        const mounted = waitForMount(page);
        const { endpoint, token } = await expressConfig(page);
        await mounted;

        const lineOf = (cart) =>
            mysql(
                `SELECT id_product_attribute FROM ps_cart_product WHERE id_cart = ${Number(cart.expressCartId)};`
            ).trim();

        const fromGroups = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: product,
            productAttributeId: 0,
            groups,
            quantity: 1,
        });

        expect(lineOf(fromGroups), 'the posted group[] values pick the combination').toBe(other);

        const nothing = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: product,
            productAttributeId: 0,
            quantity: 1,
        });

        expect(lineOf(nothing), 'with nothing posted, the default combination').toBe(
            defaultCombination
        );

        // Regression: a stale id from the details block beat the form the shopper
        // sees. PrestaShop's CartController lets group[] decide.
        const stale = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: product,
            productAttributeId: Number(defaultCombination),
            groups,
            quantity: 1,
        });

        expect(lineOf(stale), 'the posted group[] values beat a stale id').toBe(other);

        // Regression: core throws when no combination matches, so the default was
        // never reached and the shopper saw "Cannot retrieve the id_product_attribute".
        const unmatched = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: product,
            productAttributeId: 0,
            groups: { 1: 999999 },
            quantity: 1,
        });

        expect(unmatched.ok, JSON.stringify(unmatched)).toBe(true);
        expect(lineOf(unmatched), 'no match falls back to the default').toBe(defaultCombination);
    });

    // The product id is browser-supplied and Cart::updateQty() checks neither
    // status nor visibility: a disabled product could be bought through express.
    test('a product that is not for sale cannot be put in an express cart', async ({ page }) => {
        const product = Number(fixture('simpleProductId', 'MONEI_E2E_PRODUCT_ID', '6'));
        const carts = () => Number(mysql('SELECT MAX(id_cart) FROM ps_cart;').trim());
        const { endpoint, token } = await expressConfig(page);

        mysql(`UPDATE ps_product_shop SET active = 0 WHERE id_product = ${product};`);

        try {
            const before = carts();
            const body = await post(page, endpoint, {
                action: 'addToCart',
                token,
                productId: product,
                quantity: 1,
            });

            expect(body.ok).toBe(false);
            expect(body.message).toMatch(/not available/i);
            expect(carts()).toBe(before);
        } finally {
            mysql(`UPDATE ps_product_shop SET active = 1 WHERE id_product = ${product};`);
        }
    });

    // Visibility "nowhere" hides a product from listings only: PrestaShop still
    // sells it on its own URL, so express must too.
    test('a product hidden from listings can still be bought', async ({ page }) => {
        const product = Number(fixture('simpleProductId', 'MONEI_E2E_PRODUCT_ID', '6'));
        const visibility = mysql(
            `SELECT visibility FROM ps_product_shop WHERE id_product = ${product} LIMIT 1;`
        ).trim();
        const { endpoint, token } = await expressConfig(page);

        mysql(`UPDATE ps_product_shop SET visibility = 'none' WHERE id_product = ${product};`);

        try {
            const body = await post(page, endpoint, {
                action: 'addToCart',
                token,
                productId: product,
                quantity: 1,
            });

            expect(body.ok, JSON.stringify(body)).toBe(true);
        } finally {
            mysql(
                `UPDATE ps_product_shop SET visibility = '${visibility}' WHERE id_product = ${product};`
            );
        }
    });

    test('the wallet’s state is stored, and a missing one is refused rather than guessed', async ({
        page,
    }) => {
        const us = {
            ...DETAILS,
            address: { line1: '1 Main St', city: 'Austin', zip: '73301', country: 'US' },
        };

        const { endpoint, token } = await expressConfig(page);
        const resolved = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        const ok = await post(page, endpoint, {
            action: 'createOrder',
            token,
            paymentMethod: 'paypal',
            expressCartId: resolved.expressCartId,
            email: `tx-${Date.now()}@example.com`,
            // Apple Pay's spelling of the field.
            // Apple Pay and Google Pay both normalise to `state` in the SDK result.
            shippingDetails: { ...us, address: { ...us.address, state: 'TX' } },
        });

        expect(ok.ok, JSON.stringify(ok)).toBe(true);

        const stored = mysql(
            `SELECT s.iso_code FROM ps_cart c JOIN ps_address a ON a.id_address = c.id_address_delivery ` +
                `JOIN ps_state s ON s.id_state = a.id_state WHERE c.id_cart = ${Number(resolved.expressCartId)};`
        ).trim();

        // ⚠️ The regression: the country's first state was assigned whenever one
        // was required. PrestaShop lists "AA" (Armed Forces Americas) first.
        expect(stored).toBe('TX');

        // A completed createOrder signs the new guest in, which rotates the
        // session token; the second half needs the current one.
        const again = await expressConfig(page);

        const fresh = await post(page, again.endpoint, {
            action: 'addToCart',
            token: again.token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        const refused = await post(page, again.endpoint, {
            action: 'createOrder',
            token: again.token,
            paymentMethod: 'paypal',
            expressCartId: fresh.expressCartId,
            email: `nostate-${Date.now()}@example.com`,
            shippingDetails: us,
        });

        expect(refused.ok).toBe(false);
        expect(refused.message).toMatch(/state or region/i);
    });
});
