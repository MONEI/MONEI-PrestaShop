const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { console: psConsole, getConfig, mysql, psEval, setConfig } = require('../utils/ps-cli');

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

        // What every express button does when the shopper opens a wallet.
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

    // Regression: PrestaShop gives a visitor a guest id only through the statsdata
    // module. With it off (the PrestaShop demo), the express cart had no owner and
    // every guest express payment failed with "The express cart is not available".
    test('a guest without a guest id can pay, with statsdata off', async ({ browser }) => {
        const statsdataWasOn = statsdataActive();
        psConsole(['prestashop:module', 'disable', 'statsdata']);
        const context = await browser.newContext();

        try {
            const page = await context.newPage();
            const { endpoint, token } = await expressConfig(page);

            // Opening a wallet creates the express cart, and with it the guest id.
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

    // Regression: 2.2.2 minted a guest id on every product page render, and 2.2.3's
    // first fix still created a guest and an express cart when the button mounted:
    // a ps_guest and a ps_cart row for every new visitor that runs JavaScript, the
    // growth merchants avoid by turning statsdata off.
    test('a page view adds no guest and no cart row', async ({ browser }) => {
        const statsdataWasOn = statsdataActive();
        psConsole(['prestashop:module', 'disable', 'statsdata']);
        const context = await browser.newContext();

        try {
            const rows = () =>
                mysql('SELECT COUNT(*) FROM ps_guest; SELECT MAX(id_cart) FROM ps_cart;');
            const before = rows();
            const page = await context.newPage();
            const priced = page.waitForResponse(
                (response) =>
                    response.url().includes('module/monei/express') &&
                    (response.request().postData() || '').includes('"getSelectedProductData"')
            );

            await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });
            expect((await priced).ok(), 'the button should be priced').toBe(true);
            await page.waitForLoadState('networkidle');

            expect(rows()).toBe(before);
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

        const { endpoint, token } = await expressConfig(page);

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

        // Regression: the fallback took the default combination even when it was
        // out of stock and others were not, so the cart was refused. Core's
        // CartController asks for one in stock.
        const stock = psEval(
            `echo (int) StockAvailable::getQuantityAvailableByProduct(${product}, ${Number(defaultCombination)});`
        );
        const outOfStock = psEval(`echo (int) StockAvailable::outOfStock(${product});`);
        const orderOutOfStock = getConfig('PS_ORDER_OUT_OF_STOCK');

        psEval(
            `StockAvailable::setQuantity(${product}, ${Number(defaultCombination)}, 0); StockAvailable::setProductOutOfStock(${product}, 0);`
        );
        setConfig('PS_ORDER_OUT_OF_STOCK', '0');

        try {
            const inStock = await post(page, endpoint, {
                action: 'addToCart',
                token,
                productId: product,
                productAttributeId: 0,
                quantity: 1,
            });

            expect(inStock.ok, JSON.stringify(inStock)).toBe(true);
            expect(lineOf(inStock), 'an in-stock combination, not the default').not.toBe(
                defaultCombination
            );
        } finally {
            psEval(
                `StockAvailable::setQuantity(${product}, ${Number(defaultCombination)}, ${Number(stock)}); StockAvailable::setProductOutOfStock(${product}, ${Number(outOfStock)});`
            );
            setConfig('PS_ORDER_OUT_OF_STOCK', orderOutOfStock);
        }
    });

    // The product id is browser-supplied and Cart::updateQty() checks none of what
    // CartController refuses: each case once let express sell what the shop does not.
    test('a product that is not for sale cannot be priced or put in an express cart', async ({
        page,
    }) => {
        const product = Number(fixture('simpleProductId', 'MONEI_E2E_PRODUCT_ID', '6'));
        const carts = () => Number(mysql('SELECT MAX(id_cart) FROM ps_cart;').trim());
        const { endpoint, token } = await expressConfig(page);
        const catalogMode = getConfig('PS_CATALOG_MODE');
        const customization = getConfig('PS_CUSTOMIZATION_FEATURE_ACTIVE');

        const cases = {
            disabled: {
                apply: () =>
                    mysql(`UPDATE ps_product_shop SET active = 0 WHERE id_product = ${product};`),
                undo: () =>
                    mysql(`UPDATE ps_product_shop SET active = 1 WHERE id_product = ${product};`),
            },
            'catalog mode': {
                apply: () => setConfig('PS_CATALOG_MODE', '1'),
                undo: () => setConfig('PS_CATALOG_MODE', catalogMode),
            },
            // A wallet cannot collect an engraving: core refuses the cart without it.
            'a required customization field': {
                apply: () => {
                    setConfig('PS_CUSTOMIZATION_FEATURE_ACTIVE', '1');
                    mysql(
                        `INSERT INTO ps_customization_field (id_product, type, required, is_module, is_deleted) VALUES (${product}, 1, 1, 0, 0);`
                    );
                },
                undo: () => {
                    mysql(
                        `DELETE FROM ps_customization_field WHERE id_product = ${product} AND required = 1 AND is_module = 0;`
                    );
                    setConfig('PS_CUSTOMIZATION_FEATURE_ACTIVE', customization);
                },
            },
        };

        for (const [name, { apply, undo }] of Object.entries(cases)) {
            apply();

            try {
                const before = carts();
                const priced = await post(page, endpoint, {
                    action: 'getSelectedProductData',
                    token,
                    productId: product,
                    quantity: 1,
                });
                const added = await post(page, endpoint, {
                    action: 'addToCart',
                    token,
                    productId: product,
                    quantity: 1,
                });

                expect(priced.ok, `${name}: priced`).toBe(false);
                expect(added.ok, `${name}: added`).toBe(false);
                expect(added.message, name).toMatch(/not available/i);
                expect(carts(), name).toBe(before);
            } finally {
                undo();
            }
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

    // PayPal's payer address carries only country_code. Magento stored that as the
    // billing address and refused the order; a partial one must leave the
    // delivery address on the invoice.
    test('a country-only billing address keeps the delivery address on the invoice', async ({
        page,
    }) => {
        const { endpoint, token } = await expressConfig(page);
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
            email: `payer-${Date.now()}@example.com`,
            shippingDetails: DETAILS,
            billingDetails: { name: 'Ada Lovelace', address: { country: 'ES' } },
        });

        expect(order.ok, JSON.stringify(order)).toBe(true);

        const [delivery, invoice] = mysql(
            `SELECT id_address_delivery, id_address_invoice FROM ps_cart WHERE id_cart = ${Number(cart.expressCartId)};`
        )
            .trim()
            .split(/\s+/);

        expect(invoice).toBe(delivery);
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

    // Regression (demo A10): monei.js sends empty shipping details when it cannot
    // read the PayPal order, and the order was refused with "Given iso code () is
    // not valid" although the payer's address was in the billing details.
    test('empty shipping details fall back to the payer’s address', async ({ page }) => {
        const { endpoint, token } = await expressConfig(page);
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
            email: `empty-${Date.now()}@example.com`,
            shippingDetails: {},
            billingDetails: DETAILS,
        });

        expect(order.ok, JSON.stringify(order)).toBe(true);

        const city = mysql(
            `SELECT a.city FROM ps_cart c JOIN ps_address a ON a.id_address = c.id_address_delivery WHERE c.id_cart = ${Number(cart.expressCartId)};`
        ).trim();

        expect(city).toBe('Paris');
    });

    const quote = (page, endpoint, token, expressCartId, address, carrierId = '') =>
        post(page, endpoint, {
            action: 'getShippingOptions',
            token,
            expressCartId,
            address,
            carrierId,
        });

    const PARIS = { country: 'FR', city: 'Paris', zip: '75001' };

    // Regression: the sheet was never repriced. A shopper who picked a dearer
    // carrier, or an address in another zone, approved one total and was charged
    // another.
    test('the shipping quote prices the wallet’s address and carrier', async ({ page }) => {
        const { endpoint, token } = await expressConfig(page);
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });

        const france = await quote(page, endpoint, token, cart.expressCartId, PARIS);
        const cheapest = france.shippingOptions.find((option) => option.selected);
        const dearer = france.shippingOptions.find((option) => option.amount > cheapest.amount);

        expect(dearer, 'the shop should offer a paid carrier to France').toBeTruthy();

        const picked = await quote(page, endpoint, token, cart.expressCartId, PARIS, dearer.id);

        expect(picked.amount - france.amount).toBe(dearer.amount - cheapest.amount);
        expect(picked.shippingOptions.find((option) => option.selected).id).toBe(dearer.id);

        // createOrder refuses Spain (an identification number no wallet supplies),
        // so the wallet must not be told it can ship there.
        const spain = await quote(page, endpoint, token, cart.expressCartId, {
            country: 'ES',
            zip: '28001',
        });

        expect(spain.shippingOptions).toEqual([]);

        // Nor where createOrder cannot resolve the state.
        const texas = { country: 'US', city: 'Austin', zip: '73301' };
        const noState = await quote(page, endpoint, token, cart.expressCartId, texas);
        const withState = await quote(page, endpoint, token, cart.expressCartId, {
            ...texas,
            state: 'TX',
        });

        expect(noState.shippingOptions).toEqual([]);
        expect(withState.shippingOptions.length).toBeGreaterThan(0);
    });

    // Regression: createOrder picked the cheapest carrier whatever the sheet
    // showed, and charged its own total with nothing to compare it to.
    test('createOrder charges the carrier and the total the shopper approved, or nothing', async ({
        page,
    }) => {
        const { endpoint, token } = await expressConfig(page);
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: 1,
            productAttributeId: 1,
            quantity: 1,
        });
        const france = await quote(page, endpoint, token, cart.expressCartId, PARIS);
        const dearer = france.shippingOptions.find((option) => !option.selected);
        const approved = await quote(page, endpoint, token, cart.expressCartId, PARIS, dearer.id);
        const order = {
            action: 'createOrder',
            paymentMethod: 'paypal',
            expressCartId: cart.expressCartId,
            email: `carrier-${Date.now()}@example.com`,
            shippingDetails: DETAILS,
            carrierId: dearer.id,
        };

        const refused = await page.request.post(endpoint, {
            data: { ...order, token, amount: approved.amount + 1 },
        });
        const body = await refused.json();

        expect(refused.status()).toBe(409);
        expect(body.code).toBe('amount_changed');

        // Regression: the refusal ran after the wallet's customer was signed in,
        // which rotates the token, so every later request was refused. The
        // response carries the new one.
        const charged = await post(page, endpoint, {
            ...order,
            token: body.token,
            amount: approved.amount,
        });

        expect(charged.ok, JSON.stringify(charged)).toBe(true);

        const carrier = mysql(
            `SELECT id_carrier FROM ps_cart WHERE id_cart = ${Number(cart.expressCartId)};`
        ).trim();

        expect(carrier).toBe(dearer.id);
    });

    // Regression: a virtual cart was not given to the guest createOrder signs in,
    // so after a refusal (a changed total) the cart was no longer the session's
    // and the button could not be repriced from it. Only with a basket to return
    // to: without one the express cart stays the session cart, and PrestaShop
    // hands it to the customer on the next request.
    test('a virtual express cart stays the shopper’s after a refused total', async ({ page }) => {
        const product = Number(fixture('virtualProductId', 'MONEI_E2E_VIRTUAL_ID', '12'));

        await page.goto('/2-9-brown-bear-printed-sweater.html', { waitUntil: 'domcontentloaded' });
        await page.locator('[data-button-action="add-to-cart"]').first().click();
        await expect(page.locator('#blockcart-modal, .cart-content').first()).toBeVisible({
            timeout: 20000,
        });

        const { endpoint, token } = await expressConfig(page);
        const cart = await post(page, endpoint, {
            action: 'addToCart',
            token,
            productId: product,
            quantity: 1,
        });
        const order = {
            action: 'createOrder',
            paymentMethod: 'paypal',
            expressCartId: cart.expressCartId,
            email: `virtual-${Date.now()}@example.com`,
        };

        const refused = await post(page, endpoint, { ...order, token, amount: cart.amount + 1 });

        expect(refused.code).toBe('amount_changed');

        const repriced = await post(page, endpoint, {
            action: 'getCartDetails',
            token: refused.token,
            expressCartId: cart.expressCartId,
        });

        expect(repriced.ok, JSON.stringify(repriced)).toBe(true);
    });
});
