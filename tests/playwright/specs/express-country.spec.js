const { test, expect } = require('../utils/test');
const { fixture } = require('../utils/fixtures');
const { setConfig } = require('../utils/ps-cli');

const PRODUCT = fixture(
    'simpleProductPath',
    'MONEI_E2E_PRODUCT_PATH',
    '/6-mug-the-best-is-yet-to-come.html'
);

/**
 * Express against a country whose addresses PrestaShop will not accept.
 *
 * Spain is the case that matters and it is stock PrestaShop data: ES carries
 * `need_identification_number = 1`, so Address::validateFields rejects any address
 * without a DNI. No wallet supplies a national identification number, so express
 * cannot build a valid address there.
 *
 * ⚠️ This shipped broken. The shopper approved in the wallet and then met a raw
 * "Property Address->dni is empty", in MONEI's primary market, and neither CI nor
 * the rest of this suite noticed — because nothing else completes an express order
 * with an address. That is what this file is for.
 *
 * Driven through the controller rather than the wallet sheet: Chromium cannot
 * approve an Apple Pay or Google Pay sheet, and the behaviour under test is the
 * server's, not the sheet's.
 */
test.describe('express checkout: countries needing an identification number', () => {
    test.beforeAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '1');
        setConfig('MONEI_EXPRESS_LOCATIONS', 'product,cart,checkout');
        setConfig('MONEI_EXPRESS_METHODS', 'applePay,googlePay,paypal');
        setConfig('MONEI_ALLOW_PAYPAL', '1');
    });

    test.afterAll(() => {
        setConfig('MONEI_EXPRESS_ENABLED', '');
    });

    /**
     * @param {import('@playwright/test').Page} page - Page
     * @return {Promise<{endpoint: string, token: string}>} Express client config
     */
    const expressConfig = async (page) => {
        await page.goto(PRODUCT, { waitUntil: 'domcontentloaded' });

        const config = await page.evaluate(() =>
            typeof window.moneiExpress === 'undefined' ? null : window.moneiExpress
        );

        expect(config, 'express should be published to the product page').not.toBeNull();

        return config;
    };

    // The shape monei.js v3 emits on submit: the address nested under `address`.
    const DETAILS = {
        name: 'Ada Lovelace',
        phone: '600000000',
        address: { line1: 'Calle Mayor 1', city: 'Madrid', zip: '28013' },
    };

    test('refuses a Spanish address with a message the shopper can act on', async ({ page }) => {
        const { endpoint, token } = await expressConfig(page);

        const cart = await (
            await page.request.post(endpoint, {
                data: {
                    action: 'addToCart',
                    token,
                    productId: 1,
                    productAttributeId: 1,
                    quantity: 1,
                },
            })
        ).json();

        const response = await page.request.post(endpoint, {
            data: {
                action: 'createOrder',
                token,
                paymentMethod: 'paypal',
                // The express cart is adopted only at createOrder, never at mount.
                expressCartId: cart.expressCartId,
                email: 'express-country-guard@example.com',
                shippingDetails: { ...DETAILS, address: { ...DETAILS.address, country: 'ES' } },
            },
        });

        const body = await response.json();

        expect(body.ok, 'express must not complete for a country requiring a DNI').toBe(false);

        // The point of the guard: not that it fails, but that what the shopper is
        // shown is actionable. A leaked "Property Address->dni is empty" is the
        // regression this catches.
        expect(body.message).not.toContain('dni');
        expect(body.message).not.toContain('Property Address');
        expect(body.message).toMatch(/standard checkout/i);
    });

    test('still completes for a country that requires no identification number', async ({
        page,
    }) => {
        const { endpoint, token } = await expressConfig(page);

        const cart = await (
            await page.request.post(endpoint, {
                data: {
                    action: 'addToCart',
                    token,
                    productId: 1,
                    productAttributeId: 1,
                    quantity: 1,
                },
            })
        ).json();

        const response = await page.request.post(endpoint, {
            data: {
                action: 'createOrder',
                token,
                paymentMethod: 'paypal',
                expressCartId: cart.expressCartId,
                email: 'express-country-ok@example.com',
                shippingDetails: {
                    ...DETAILS,
                    address: {
                        line1: '1 Rue de Rivoli',
                        city: 'Paris',
                        zip: '75001',
                        country: 'FR',
                    },
                },
            },
        });

        const body = await response.json();

        // Proves the guard is scoped to the countries that need it, rather than
        // quietly disabling express everywhere.
        expect(body.ok, JSON.stringify(body)).toBe(true);
        expect(body.paymentId).toBeTruthy();
    });
});
