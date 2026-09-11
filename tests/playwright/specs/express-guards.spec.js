const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { mysql, setConfig } = require('../utils/ps-cli');

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
