const crypto = require('crypto');
const { test, expect } = require('../utils/test');
const { login, moduleConfigureUrl, openModuleConfiguration } = require('../utils/admin');
const { ADMIN_PATH, baseUrl } = require('../utils/env');
const { getConfig, mysql, setConfig } = require('../utils/ps-cli');

/**
 * Connect with MONEI: the admin checks of the start and disconnect branches of
 * getContent, the callback route, and the Connect button.
 *
 * The suite branches on the URL the shop builds, not on whether
 * MONEI_E2E_BASE_URL is set: the broker refuses a callback URL that is not https,
 * so an http shop shows "Use manual keys" and an https shop the button.
 */
const HTTPS = baseUrl().startsWith('https://');

const CONNECT_SKIP_REASON =
    `Connect with MONEI needs an https callback URL; this run targets ${baseUrl()}. ` +
    'Run the stack with its tunnel to run it.';

const MANUAL_SKIP_REASON = `This run targets the https shop ${baseUrl()}, which shows the Connect button instead of "Use manual keys".`;

const CREDENTIALS = [
    'MONEI_ACCOUNT_ID',
    'MONEI_API_KEY',
    'MONEI_TEST_ACCOUNT_ID',
    'MONEI_TEST_API_KEY',
];

/**
 * The fake Live credentials a connect would leave. The shop stays in test mode, so
 * no request goes out with the fake key.
 */
const FAKE_LIVE = {
    MONEI_ACCOUNT_ID: 'e2e-live-account',
    MONEI_API_KEY: 'mk_live_e2e_not_a_key',
    MONEI_SIGNING_SECRET: 'mksec_live_e2e',
};

/**
 * Names of the stored connect states.
 * @return {string} Newline separated names
 */
const stateRows = () =>
    mysql(
        "SELECT name FROM ps_configuration WHERE name LIKE 'MONEI\\_CONNECT\\_STATE\\_%' ORDER BY name;"
    );

/**
 * A digest of the stored credentials, so a failed comparison never prints a key.
 * @return {string} SHA-256 of the four values
 */
const credentialsDigest = () =>
    crypto
        .createHash('sha256')
        .update(JSON.stringify(CREDENTIALS.map(getConfig)))
        .digest('hex');

/**
 * Keep configuration values, to put them back after a test changes them.
 * @param {string[]} keys - Configuration keys
 * @return {Function} Restores the values
 */
const keep = (keys) => {
    const before = Object.fromEntries(keys.map((key) => [key, getConfig(key)]));

    return () => Object.entries(before).forEach(([key, value]) => setConfig(key, value));
};

/**
 * The module configure URL, with its token replaced or removed.
 * @param {string|null} token - Token to send, null for none
 * @return {string} Back office URL
 */
const configureUrlWithToken = (token) => {
    const url = `${ADMIN_PATH}/${moduleConfigureUrl()}`.replace(/&token=[^&]*/, '');

    return token === null ? url : `${url}&token=${token}`;
};

/**
 * Post a getContent branch three ways: with no employee session, and signed in with
 * a missing and with a wrong token.
 * @param {import('@playwright/test').Page}       page       - Page
 * @param {import('@playwright/test').Playwright} playwright - Playwright
 * @param {string}                                submit     - Submit name
 * @return {Promise<string[]>} Where each request ended: the login path, then the
 *                             refusal of each signed-in request
 */
const postWithoutTheAdminCheck = async (page, playwright, submit) => {
    const form = { [submit]: '1' };
    const anonymous = await playwright.request.newContext({ baseURL: baseUrl() });
    const noSession = await anonymous.post(configureUrlWithToken('0123456789abcdef'), { form });
    // PrestaShop 9 lands on /login, PrestaShop 8 on controller=AdminLogin.
    const landings = [noSession.url()];
    await anonymous.dispose();

    await login(page);
    for (const token of [null, '0123456789abcdef0123456789abcdef']) {
        const response = await page.request.post(configureUrlWithToken(token), { form });
        const refusal = (await response.text()).match(/security token is not valid|Invalid token/i);
        landings.push(`${response.status()} ${refusal ? 'refused' : 'not refused'}`);
    }

    return landings;
};

test.describe('Connect with MONEI', () => {
    test('a start without the admin check stores no state', async ({ page, playwright }) => {
        const before = stateRows();

        const landings = await postWithoutTheAdminCheck(page, playwright, 'submitMoneiConnect');

        // No session ends on the login page. ⚠️ PrestaShop 9 answers a wrong or
        // missing token with a redirect to its own route plus a valid token, and
        // runs getContent: the branch's own token check is what refuses these.
        expect(landings[0]).toMatch(/login/i);
        expect(landings.slice(1)).toEqual(['200 refused', '200 refused']);
        expect(stateRows()).toBe(before);
    });

    test('a disconnect without the admin check keeps the credentials', async ({
        page,
        playwright,
    }) => {
        expect(getConfig('MONEI_TEST_API_KEY'), 'the seeded store has a key to lose').not.toBe('');
        const before = credentialsDigest();

        const landings = await postWithoutTheAdminCheck(page, playwright, 'submitMoneiDisconnect');

        expect(landings[0]).toMatch(/login/i);
        expect(landings.slice(1)).toEqual(['200 refused', '200 refused']);
        expect(credentialsDigest(), 'credentials unchanged').toBe(before);
    });

    test('a forged state on the callback is refused and a stored state stays', async ({ page }) => {
        const seed = `MONEI_CONNECT_STATE_${'s'.repeat(43)}`;
        setConfig(
            seed,
            JSON.stringify({
                verifier: 'seed-verifier',
                redirectUri: `${baseUrl()}/module/monei/connect`,
                scope: { idShopGroup: 0, idShop: 0 },
                createdAt: Math.floor(Date.now() / 1000),
            })
        );
        const before = getConfig(seed);

        try {
            // With a code: only the state check can refuse it.
            const response = await page.request.get(
                `/index.php?fc=module&module=monei&controller=connect&code=forged-code&state=${'f'.repeat(43)}`
            );

            expect(response.status()).toBe(400);
            expect(response.headers()['referrer-policy']).toBe('no-referrer');
            expect(await response.text()).toContain('not valid');
            expect(getConfig(seed)).toBe(before);
        } finally {
            mysql(`DELETE FROM ps_configuration WHERE name = '${seed}';`);
        }
    });

    test('a connected mode settles an unsigned callback through its payment row and a fetch', async ({
        playwright,
    }) => {
        // A payment whose cart has no order yet.
        const pendingPaymentId = 'e2e0'.repeat(8);
        mysql(
            'INSERT INTO ps_monei2_payment (id_payment, id_cart, amount, status, date_add, date_upd) ' +
                `VALUES ('${pendingPaymentId}', 999999, 100, 'PENDING', NOW(), NOW());`
        );
        // Live is connected with a key MONEI refuses; Test keeps the seeded manual key.
        const restore = keep(['MONEI_ACCOUNT_ID', 'MONEI_API_KEY', 'MONEI_SIGNING_SECRET']);
        Object.entries(FAKE_LIVE).forEach(([key, value]) => setConfig(key, value));
        mysql(`DELETE FROM ps_configuration WHERE name = 'MONEI_CALLBACK_FETCH_ATTEMPTS';`);
        const orderPayments = () => mysql('SELECT COUNT(*) FROM ps_monei2_order_payment;');
        const before = orderPayments();
        const callback = await playwright.request.newContext({ baseURL: baseUrl() });

        try {
            const post = async (id) =>
                (
                    await callback.post('/index.php?fc=module&module=monei&controller=validation', {
                        data: { id, livemode: true, status: 'SUCCEEDED', orderId: '1' },
                        maxRedirects: 0,
                    })
                ).status();

            // No header: the 401 is only for a shop without a scoped key.
            expect({
                // Not a payment of this shop: nothing for MONEI to retry.
                unknown: await post('0'.repeat(32)),
                // A row: the fetch runs with the stored key, which MONEI refuses.
                pending: await post(pendingPaymentId),
            }).toEqual({ unknown: 200, pending: 503 });
            expect(orderPayments(), 'the forged SUCCEEDED body changed nothing').toBe(before);
        } finally {
            await callback.dispose();
            restore();
            mysql(`DELETE FROM ps_monei2_payment WHERE id_payment = '${pendingPaymentId}';`);
            mysql(`DELETE FROM ps_configuration WHERE name = 'MONEI_CALLBACK_FETCH_ATTEMPTS';`);
        }
    });

    test('a settings save that changes a key clears its signing secret only', async ({ page }) => {
        const restore = keep([
            'MONEI_ACCOUNT_ID',
            'MONEI_API_KEY',
            'MONEI_SIGNING_SECRET',
            'MONEI_TEST_SIGNING_SECRET',
        ]);
        setConfig('MONEI_SIGNING_SECRET', 'mksec_live_e2e');
        setConfig('MONEI_TEST_SIGNING_SECRET', 'mksec_test_e2e');

        try {
            await openModuleConfiguration(page);
            await page.locator('a[href="#panel-conf-1"]').click();
            // Test mode hides the Live fields; a hidden field is still posted.
            await page.locator('input[name="MONEI_API_KEY"]').evaluate((input) => {
                input.value = 'pk_live_e2e_changed';
            });
            await page.locator('button[name="submitMoneiModule"]').click();
            await expect(page.locator('.alert-success').first()).toBeVisible();

            expect(getConfig('MONEI_API_KEY')).toBe('pk_live_e2e_changed');
            expect(getConfig('MONEI_SIGNING_SECRET'), 'the changed key loses its secret').toBe('');
            expect(
                getConfig('MONEI_TEST_SIGNING_SECRET'),
                'the unchanged key keeps its secret'
            ).toBe('mksec_test_e2e');
        } finally {
            restore();
        }
    });

    test('a settings tab left open during a connect keeps the new key and secret', async ({
        page,
    }) => {
        const restore = keep(['MONEI_TEST_API_KEY', 'MONEI_TEST_SIGNING_SECRET']);

        try {
            await openModuleConfiguration(page);
            await page.locator('a[href="#panel-conf-1"]').click();
            // The connect in another tab, after this form rendered the manual key.
            setConfig('MONEI_TEST_API_KEY', 'mk_test_e2e_not_a_key');
            setConfig('MONEI_TEST_SIGNING_SECRET', 'mksec_test_e2e');
            await page.locator('button[name="submitMoneiModule"]').click();

            await expect(
                page.locator('.alert', { hasText: 'The API keys changed in another tab' }).first()
            ).toBeVisible();
            expect(getConfig('MONEI_TEST_API_KEY')).toBe('mk_test_e2e_not_a_key');
            expect(getConfig('MONEI_TEST_SIGNING_SECRET')).toBe('mksec_test_e2e');
        } finally {
            restore();
        }
    });

    test('the settings page never shows a connected key, and an empty field keeps it', async ({
        page,
    }) => {
        const restore = keep(['MONEI_TEST_API_KEY', 'MONEI_TEST_SIGNING_SECRET']);
        setConfig('MONEI_TEST_API_KEY', 'mk_test_e2e_not_a_key');
        setConfig('MONEI_TEST_SIGNING_SECRET', 'mksec_test_e2e');

        try {
            await openModuleConfiguration(page);
            await page.locator('a[href="#panel-conf-1"]').click();
            const field = page.locator('input[name="MONEI_TEST_API_KEY"]');

            expect(await page.content()).not.toContain('mk_test_e2e_not_a_key');
            expect([await field.inputValue(), await field.getAttribute('placeholder')]).toEqual([
                '',
                '•••• _key',
            ]);

            await page.locator('button[name="submitMoneiModule"]').click();
            await expect(page.locator('.alert-success').first()).toBeVisible();
            expect(getConfig('MONEI_TEST_API_KEY')).toBe('mk_test_e2e_not_a_key');
            expect(getConfig('MONEI_TEST_SIGNING_SECRET')).toBe('mksec_test_e2e');
        } finally {
            restore();
        }
    });

    test('a settings tab rendered before a Disconnect restores neither keys nor mode', async ({
        page,
    }) => {
        const restore = keep([
            ...CREDENTIALS,
            'MONEI_TEST_SIGNING_SECRET',
            'MONEI_PRODUCTION_MODE',
        ]);
        setConfig('MONEI_TEST_API_KEY', 'mk_test_e2e_not_a_key');

        try {
            await openModuleConfiguration(page);
            await page.locator('a[href="#panel-conf-1"]').click();
            // Another tab disconnects and switches the mode after this form rendered.
            ['MONEI_TEST_ACCOUNT_ID', 'MONEI_TEST_API_KEY', 'MONEI_TEST_SIGNING_SECRET'].forEach(
                (key) => setConfig(key, '')
            );
            setConfig('MONEI_PRODUCTION_MODE', '1');
            await page.locator('button[name="submitMoneiModule"]').click();

            await expect(
                page.locator('.alert', { hasText: 'The API keys changed in another tab' }).first()
            ).toBeVisible();
            expect(
                ['MONEI_TEST_ACCOUNT_ID', 'MONEI_TEST_API_KEY', 'MONEI_PRODUCTION_MODE'].map(
                    getConfig
                )
            ).toEqual(['', '', '1']);
        } finally {
            restore();
        }
    });

    test('an http shop is not invited to connect', async ({ page }) => {
        test.skip(HTTPS, MANUAL_SKIP_REASON);

        await openModuleConfiguration(page);

        await expect(
            page.locator('.alert', {
                hasText: 'Connect with MONEI to stop storing your account API key.',
            })
        ).toHaveCount(0);
    });

    test('an http shop shows "Use manual keys" and no button', async ({ page }) => {
        test.skip(HTTPS, MANUAL_SKIP_REASON);

        await openModuleConfiguration(page);

        await expect(page.locator('#monei-connect-manual')).toContainText('Use manual keys');
        await expect(page.locator('#monei-connect')).toHaveCount(0);
    });

    test('an https shop starts at the broker with S256 and its callback', async ({ page }) => {
        test.skip(!HTTPS, CONNECT_SKIP_REASON);

        await openModuleConfiguration(page);
        const form = page.locator('form:has(#monei-connect)');
        // The button opens a new tab, so the settings page stays open.
        expect(await form.getAttribute('target')).toBe('_blank');
        const fields = {
            submitMoneiConnect: '1',
            monei_connect_token: await form
                .locator('input[name="monei_connect_token"]')
                .inputValue(),
        };

        // Each hop by hand, so the broker page itself is never loaded: the dev
        // blocklist may hold the tunnel host. PrestaShop 9 sends the module URL on
        // to its own route with a 308 first.
        let response = await page.request.post(await form.getAttribute('action'), {
            form: fields,
            maxRedirects: 0,
        });
        if (response.status() === 308) {
            response = await page.request.post(response.headers().location, {
                form: fields,
                maxRedirects: 0,
            });
        }
        expect(response.status()).toBe(302);
        const location = new URL(response.headers().location);
        const state = location.searchParams.get('state');

        try {
            expect(location.pathname).toBe('/integrations/authorize');
            expect(location.searchParams.get('client_id')).toBe('prestashop');
            expect(location.searchParams.get('code_challenge_method')).toBe('S256');

            const redirectUri = new URL(location.searchParams.get('redirect_uri'));
            expect(redirectUri.origin).toBe(new URL(baseUrl()).origin);
            expect(`${redirectUri.pathname}${redirectUri.search}`).toMatch(
                /^\/(?:[a-z]{2}\/)?module\/monei\/connect$|^\/index\.php\?fc=module&module=monei&controller=connect(?:&id_lang=\d+)?$/
            );
            expect(redirectUri.href).not.toMatch(/token/i);
            expect(stateRows()).toContain(`MONEI_CONNECT_STATE_${state}`);
        } finally {
            mysql(`DELETE FROM ps_configuration WHERE name = 'MONEI_CONNECT_STATE_${state}';`);
        }
    });

    test('a shop with only manual keys has no Disconnect button', async ({ page }) => {
        expect(
            getConfig('MONEI_TEST_API_KEY').startsWith('pk_'),
            'the seeded store uses a manual key'
        ).toBe(true);

        await openModuleConfiguration(page);

        await expect(page.locator('#monei-disconnect')).toHaveCount(0);
    });

    test('a manual-key shop is invited to connect, and an employee can dismiss it', async ({
        page,
    }) => {
        test.skip(!HTTPS, CONNECT_SKIP_REASON);
        expect(
            getConfig('MONEI_TEST_API_KEY').startsWith('pk_'),
            'the seeded store uses a manual key'
        ).toBe(true);
        const notice = page.locator('.alert', {
            hasText: 'Connect with MONEI to stop storing your account API key.',
        });

        // The dashboard the login lands on is a legacy page; the module page is one
        // where PrestaShop 9 prints no informations of the legacy controller.
        await login(page);
        await expect(page).toHaveURL(/controller=AdminDashboard/);
        await expect(notice.first()).toBeVisible();
        await openModuleConfiguration(page);
        await expect(notice.first()).toBeVisible();

        await notice.first().getByRole('link', { name: 'Dismiss' }).click();
        await page.waitForLoadState('domcontentloaded');
        await openModuleConfiguration(page);

        await expect(notice).toHaveCount(0);
    });

    test('Disconnect clears the connected mode and keeps a manual one', async ({ page }) => {
        const restore = keep([
            ...CREDENTIALS,
            'MONEI_SIGNING_SECRET',
            'MONEI_TEST_SIGNING_SECRET',
            'MONEI_PRODUCTION_MODE',
        ]);
        Object.entries(FAKE_LIVE).forEach(([key, value]) => setConfig(key, value));
        setConfig('MONEI_PRODUCTION_MODE', '0');

        try {
            await openModuleConfiguration(page);
            await page.locator('a[href="#panel-conf-1"]').click();
            await page.locator('#monei-disconnect').click();

            await expect(
                page.locator('.alert', { hasText: 'MONEI disconnected.' }).first()
            ).toBeVisible();
            await expect(page.locator('#monei-disconnect')).toHaveCount(0);
            expect(
                ['MONEI_ACCOUNT_ID', 'MONEI_API_KEY', 'MONEI_SIGNING_SECRET'].map(getConfig),
                'the connected Live mode is cleared'
            ).toEqual(['', '', '']);
            expect(
                getConfig('MONEI_TEST_API_KEY').startsWith('pk_test_'),
                'the manual Test key stays'
            ).toBe(true);
        } finally {
            restore();
        }
    });
});
