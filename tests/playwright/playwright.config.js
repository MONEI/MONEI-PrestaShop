const { defineConfig, devices } = require('@playwright/test');
const { baseUrl } = require('./utils/env');

/**
 * Playwright config for the MONEI PrestaShop E2E suite.
 *
 * The suite drives a real PrestaShop store with a real MONEI test account, so it
 * mutates global store state (card field layout, express settings, order states).
 * That is why it runs single worker and non parallel.
 */
module.exports = defineConfig({
    testDir: './specs',
    outputDir: './test-results',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    // ⚠️ Retries exist for the transport, not for the module. The suite reaches the
    // store through an ngrok tunnel, and the free tier resets connections and
    // rotates its hostname; `net::ERR_CONNECTION_RESET` mid-navigation is a normal
    // occurrence there and says nothing about the code. A failure that survives a
    // retry is real and must be treated as real — never raise this number to make
    // a genuinely failing spec go green.
    retries: 2,
    // MONEI mounts its iframes after an init delay, then a real payment round
    // trip follows, so give each test room.
    timeout: 180000,
    expect: { timeout: 30000 },
    reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
    use: {
        baseURL: baseUrl(),
        actionTimeout: 30000,
        navigationTimeout: 60000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
