const base = require('@playwright/test');
const { baseUrl, isNgrok } = require('./env');

/**
 * Playwright test with the ngrok interstitial bypass scoped to the store.
 *
 * ⚠️ The `ngrok-skip-browser-warning` header must reach the store and nothing
 * else. Setting it globally through `extraHTTPHeaders` looks equivalent and is
 * not: a custom header on a cross origin request turns that request into a CORS
 * preflight, and `api.monei.com` does not list this header in
 * `access-control-allow-headers`. Every browser side MONEI call then fails with a
 * bare `net::ERR_FAILED`, the card form reports "Failed to fetch", and nothing in
 * the store logs explains it — the API itself is perfectly healthy.
 */
const test = base.test.extend({
    page: async ({ page }, use) => {
        if (isNgrok()) {
            const { origin } = new URL(baseUrl());

            await page.route(`${origin}/**`, (route) =>
                route.continue({
                    headers: {
                        ...route.request().headers(),
                        'ngrok-skip-browser-warning': 'true',
                    },
                })
            );
        }

        await use(page);
    },
});

module.exports = { expect: base.expect, test };
