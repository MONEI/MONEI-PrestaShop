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
    // ⚠️ Generous on purpose, and it has to exceed the sum of the waits a payment
    // test performs, not just the slowest one. A card journey waits for the 3D
    // Secure challenge to appear (up to 90s) and then for the order confirmation
    // (up to 120s). At 180s a slow-but-healthy challenge blew the test timeout
    // while the payment itself was completing perfectly — token, payment and
    // challenge all answered 200.
    timeout: 300000,
    expect: {
        timeout: 30000,
        // Same contract as monei-js and the other MONEI plugins. macOS baselines
        // are the single source of truth; a Linux run compares against them and
        // the threshold absorbs font anti-aliasing drift. A per-test bump needs
        // a comment saying why.
        toHaveScreenshot: { threshold: 0.3, maxDiffPixelRatio: 0.1, animations: 'disabled' },
    },
    snapshotPathTemplate: '{testDir}/{testFilePath}-snapshots/{arg}-{projectName}-darwin{ext}',
    reporter: [['list'], ['html', { outputFolder: './playwright-report', open: 'never' }]],
    use: {
        baseURL: baseUrl(),
        actionTimeout: 30000,
        navigationTimeout: 60000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
        // Rendering only. Payment journeys on a phone emulation would double a
        // 7-minute run for no extra coverage of the module's own code.
        {
            name: 'mobile',
            use: { ...devices['Pixel 5'] },
            testMatch: /payment-methods-rendering\.spec\.js/,
        },
    ],
});
