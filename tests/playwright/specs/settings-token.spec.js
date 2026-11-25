const { test, expect } = require('../utils/test');
const { login, moduleConfigureUrl } = require('../utils/admin');
const { ADMIN_PATH, baseUrl } = require('../utils/env');
const { getConfig, setConfig } = require('../utils/ps-cli');

/**
 * The module settings refuse a save without the module's form token.
 *
 * ⚠️ PrestaShop 9 answers a wrong or missing admin token on the module configure
 * URL with a redirect to its own route that adds a valid token, and then runs
 * getContent. Without the form token, a cross-site POST from a signed-in
 * employee's browser would change the settings, the API keys among them. The saves
 * through the forms themselves are covered by the other settings specs.
 */
test.describe('settings: form token', () => {
    test('a save without the form token changes nothing', async ({ page, playwright }) => {
        const before = getConfig('MONEI_EXPRESS_LOCATIONS');
        const form = { submitMoneiModuleExpress: '1', 'MONEI_EXPRESS_LOCATIONS[]': 'checkout' };
        const url = `${ADMIN_PATH}/${moduleConfigureUrl()}`;

        try {
            const anonymous = await playwright.request.newContext({ baseURL: baseUrl() });
            const noSession = await anonymous.post(url, { form });
            const noSessionPath = new URL(noSession.url()).pathname;
            await anonymous.dispose();

            await login(page);
            const landings = [];
            for (const target of [
                url,
                url.replace(/&token=[^&]*/, ''),
                url.replace(/&token=[^&]*/, '&token=0123'),
            ]) {
                const response = await page.request.post(target, { form });
                const refused = /security token is not valid|Invalid token/i.test(
                    await response.text()
                );
                landings.push(`${response.status()} ${refused ? 'refused' : 'not refused'}`);
            }

            expect(noSessionPath).toMatch(/login/);
            // The valid admin token alone does not do: only the form token does.
            expect(landings).toEqual(['200 refused', '200 refused', '200 refused']);
            expect(getConfig('MONEI_EXPRESS_LOCATIONS')).toBe(before);
        } finally {
            setConfig('MONEI_EXPRESS_LOCATIONS', before);
        }
    });
});
