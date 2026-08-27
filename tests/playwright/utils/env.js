const { execFileSync } = require('child_process');
const path = require('path');

require('dotenv').config({
    path: path.resolve(__dirname, '../.env'),
    quiet: true,
});

/**
 * Module root.
 */
const MODULE_ROOT = path.resolve(__dirname, '../../..');

/**
 * Read a required E2E setting.
 *
 * The suite drives a browser against one store and reconfigures another through
 * the PrestaShop CLI. Defaulting either target would let a misconfigured run take
 * real test payments on one store while changing settings on a different one, so
 * both are explicit or the run stops here.
 *
 * @param {string} name - Environment variable name
 * @param {string} hint - What the value must point at
 * @return {string} The value
 */
const requireEnv = (name, hint) => {
    // A secret pasted into a CI store almost always carries a trailing newline,
    // which turns a valid credential into a 401 that reads like a wrong key.
    const value = (process.env[name] || '').trim();

    if (!value) {
        throw new Error(
            `${name} is not set. ${hint}\n` +
                'Copy tests/playwright/.env.example to tests/playwright/.env ' +
                'and set it there. See tests/playwright/README.md.'
        );
    }

    return value;
};

/**
 * Docker Compose project name of the Flashlight stack.
 *
 * The compose file at the PrestaShop parent directory sets `name: tunnel1`, so
 * the container is `tunnel1-prestashop-1`. Overridable because a developer may
 * run the stack under another project name.
 *
 * @return {string} Container name
 */
const containerName = () =>
    (process.env.MONEI_E2E_PS_CONTAINER || 'tunnel1-prestashop-1').trim();

/**
 * Ask the store what domain it believes it is served on.
 *
 * The Flashlight compose file sets `NGROK_TUNNEL_AUTO_DETECT`, so the shop domain
 * is the ngrok tunnel rather than `localhost:8000`, and the published port merely
 * 302s to it. The tunnel URL changes every time the stack restarts, which makes
 * pinning it in `.env` a value that goes stale silently — so it is read from the
 * store instead.
 *
 * ⚠️ Deliberately shells out here rather than using `ps-cli.js`: that module
 * requires this one, and importing it back would be a cycle.
 *
 * @return {string} Shop URL, empty when the container cannot answer
 */
const detectShopUrl = () => {
    try {
        const domain = execFileSync(
            'docker',
            [
                'exec',
                containerName(),
                'php',
                '-r',
                "require '/var/www/html/config/config.inc.php';" +
                    "echo (Configuration::get('PS_SSL_ENABLED') ? 'https://' : 'http://')" +
                    ". Configuration::get('PS_SHOP_DOMAIN_SSL');",
            ],
            { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'], timeout: 30000 }
        ).trim();

        return /^https?:\/\/.+/.test(domain) ? domain : '';
    } catch (error) {
        return '';
    }
};

let cachedBaseUrl;

/**
 * URL the browser drives and pays on.
 *
 * An explicit `MONEI_E2E_BASE_URL` always wins. Otherwise the store is asked, so
 * a fresh tunnel needs no configuration. The published port is the last resort,
 * for a stack running without ngrok — card journeys cannot complete there, see
 * `supportsThreeDs`.
 *
 * @return {string} Base URL
 */
const baseUrl = () => {
    if (cachedBaseUrl === undefined) {
        const configured = (process.env.MONEI_E2E_BASE_URL || '').trim();
        cachedBaseUrl = (configured || detectShopUrl() || 'http://localhost:8000').replace(
            /\/$/,
            ''
        );
    }

    return cachedBaseUrl;
};

/**
 * Whether the store is reachable from outside this machine over HTTPS.
 *
 * 3D Secure sends the shopper's browser to the issuer and back to the store, and
 * the challenge is framed over HTTPS. A store on `http://localhost` satisfies
 * neither half: the page is plain HTTP, and MONEI cannot reach the host at all.
 * The challenge then never renders, so a card journey that expects one waits for
 * an order confirmation that can never arrive.
 *
 * The Flashlight compose file ships an ngrok service for exactly this reason.
 * Point `MONEI_E2E_BASE_URL` at the tunnel to run the card specs.
 *
 * @return {boolean} Whether 3DS can complete against this store
 */
const supportsThreeDs = () => baseUrl().startsWith('https://');

/**
 * Whether the store is served through an ngrok tunnel.
 *
 * @return {boolean} Whether the base URL is an ngrok host
 */
const isNgrok = () => /\bngrok[\w-]*\.(app|dev|io)$/.test(new URL(baseUrl()).hostname);

/**
 * Headers every request needs for this base URL.
 *
 * ⚠️ A free ngrok tunnel serves an interstitial warning page to browsers instead
 * of the site, so without this header every navigation lands on a blank page and
 * every selector times out — a failure that reads like the store being broken.
 * The header is what tells ngrok the caller is not a human clicking through.
 *
 * @return {Object} Extra HTTP headers
 */
const extraHeaders = () => (isNgrok() ? { 'ngrok-skip-browser-warning': 'true' } : {});

/**
 * Reason shown when a spec is skipped for want of a public HTTPS store.
 */
const THREE_DS_SKIP_REASON =
    `3D Secure needs a publicly reachable HTTPS store; this run targets ${baseUrl()}. ` +
    'Start the ngrok service in the Flashlight compose stack and set ' +
    'MONEI_E2E_BASE_URL to the tunnel URL to run it.';

/**
 * Back office path.
 *
 * Flashlight serves the back office from `admin-dev`, unlike a production install
 * where the directory is renamed to a random string.
 */
const ADMIN_PATH = (process.env.MONEI_E2E_ADMIN_PATH || '/admin-dev').replace(/\/$/, '');

const ADMIN_USER = process.env.MONEI_E2E_ADMIN_USER || 'admin@prestashop.com';
const ADMIN_PASSWORD = process.env.MONEI_E2E_ADMIN_PASSWORD || 'prestashop';

module.exports = {
    ADMIN_PASSWORD,
    detectShopUrl,
    ADMIN_PATH,
    ADMIN_USER,
    MODULE_ROOT,
    THREE_DS_SKIP_REASON,
    baseUrl,
    containerName,
    extraHeaders,
    isNgrok,
    requireEnv,
    supportsThreeDs,
};
