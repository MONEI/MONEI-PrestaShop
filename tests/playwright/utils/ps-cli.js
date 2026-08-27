const { execFileSync } = require('child_process');
const { containerName } = require('./env');

/**
 * PrestaShop root inside the Flashlight container.
 */
const PS_FOLDER = '/var/www/html';

/**
 * Run a command inside the Flashlight container.
 *
 * Throws on a non zero exit so a broken fixture fails the test instead of
 * silently leaving the store in the wrong state.
 *
 * @param {string[]} args - Command and arguments
 * @return {string} Trimmed stdout
 */
const exec = (args) =>
    execFileSync('docker', ['exec', containerName(), ...args], {
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', 'pipe'],
        // A container that accepts the command and never answers would otherwise
        // hold a seed or a beforeAll hook with no upper bound. A wedged Docker
        // daemon is a real failure mode, not a hypothetical one.
        timeout: 120000,
    }).trim();

/**
 * Run a PrestaShop console command.
 *
 * @param {string[]} args - Console arguments
 * @return {string} Trimmed stdout
 */
const console_ = (args) =>
    exec(['php', '-d', 'memory_limit=-1', `${PS_FOLDER}/bin/console`, '--no-interaction', ...args]);

/**
 * Evaluate PHP with PrestaShop bootstrapped.
 *
 * PrestaShop has no `wp eval` equivalent: the console exposes a fixed command
 * list and nothing that reads or writes a Configuration value. Bootstrapping
 * `config.inc.php` and running PHP is the supported way to reach the same API the
 * module itself uses, so a fixture manipulates the store exactly as the module
 * would rather than writing rows behind its back.
 *
 * ⚠️ The snippet is passed as a single argv element, never through a shell, so it
 * needs no quoting. Do not interpolate untrusted values into it.
 *
 * @param {string} code - PHP statements to run
 * @return {string} Trimmed stdout
 */
const psEval = (code) =>
    exec([
        'php',
        '-d',
        'memory_limit=-1',
        '-r',
        `define('_PS_ADMIN_DIR_', '${PS_FOLDER}/admin-dev'); require '${PS_FOLDER}/config/config.inc.php'; ${code}`,
    ]);

/**
 * Run a MySQL query against the store database.
 *
 * The MONEI module writes its own log lines to the `ps_log` table rather than to
 * a log file, so reading them back is a database query. See the module CLAUDE.md.
 *
 * @param {string} sql - Query to run
 * @return {string} Trimmed stdout, tab separated
 */
const mysql = (sql) =>
    exec([
        'mysql',
        '-h',
        'mysql',
        '-u',
        'root',
        '-pprestashop',
        'prestashop',
        '-N',
        '-B',
        '-e',
        sql,
    ]);

/**
 * Read a Configuration value, or an empty string when it is not set.
 *
 * @param {string} key - Configuration key
 * @return {string} Stored value
 */
const getConfig = (key) => psEval(`echo (string) Configuration::get('${key}');`);

/**
 * Write a Configuration value.
 *
 * @param {string} key   - Configuration key
 * @param {string} value - Value to store
 */
const setConfig = (key, value) =>
    psEval(`Configuration::updateValue('${key}', ${JSON.stringify(String(value))});`);

/**
 * Clear the PrestaShop cache and reset the module.
 *
 * Module changes on disk do not take effect until both happen. This is the same
 * dance the module CLAUDE.md documents for manual development, and skipping it
 * makes a spec assert against the previous build of the code.
 */
const resetModule = () => {
    exec(['sh', '-c', `rm -rf ${PS_FOLDER}/var/cache/*`]);
    console_(['cache:clear']);
    console_(['prestashop:module', 'reset', 'monei']);
};

/**
 * Install the module.
 *
 * @return {string} Console output
 */
const installModule = () => console_(['prestashop:module', 'install', 'monei']);

/**
 * Uninstall the module.
 *
 * @return {string} Console output
 */
const uninstallModule = () => console_(['prestashop:module', 'uninstall', 'monei']);

/**
 * Upgrade the module, running any pending scripts in `upgrade/`.
 *
 * This is what proves an upgrade script actually fires. A fresh install runs
 * `install()` and never touches `upgrade/`, so hooks and defaults added only to
 * `install()` would pass a fresh-install test and still reach no existing
 * merchant.
 *
 * @return {string} Console output
 */
const upgradeModule = () => console_(['prestashop:module', 'upgrade', 'monei']);

/**
 * Whether a hook is registered for the module.
 *
 * @param {string} hookName - Hook name
 * @return {boolean} Whether the module is attached to it
 */
const isHookRegistered = (hookName) =>
    psEval(
        `$m = Module::getInstanceByName('monei');` +
            `echo $m && $m->isRegisteredInHook('${hookName}') ? '1' : '';`
    ) === '1';

/**
 * Read the installed module version as PrestaShop records it.
 *
 * Read from the database rather than from `monei.php`, because that is the value
 * the upgrade machinery compares against when deciding which scripts to run.
 *
 * @return {string} Installed version
 */
const installedVersion = () =>
    psEval(
        `echo (string) Db::getInstance()->getValue('SELECT version FROM '._DB_PREFIX_.'module WHERE name = "monei"');`
    );

/**
 * Force the version PrestaShop believes is installed.
 *
 * This is what the upgrade machinery compares against when deciding which
 * scripts in `upgrade/` to run, so rewinding it is how an upgrade is replayed
 * against a store that is already current.
 *
 * @param {string} version - Version to record
 */
const setInstalledVersion = (version) =>
    mysql(`UPDATE ps_module SET version = '${version}' WHERE name = 'monei';`);

/**
 * Detach the module from a hook.
 *
 * @param {string} hookName - Hook name
 */
const unregisterHook = (hookName) =>
    psEval(
        `$m = Module::getInstanceByName('monei');` +
            `if ($m) { $m->unregisterHook('${hookName}'); }`
    );

/**
 * Delete a Configuration value.
 *
 * @param {string} key - Configuration key
 */
const deleteConfig = (key) => psEval(`Configuration::deleteByName('${key}');`);

/**
 * Read the current state of an order.
 *
 * The confirmation page only says the browser landed somewhere; the order state
 * is what says money moved, so a payment test has to read it from the store.
 *
 * @param {string|number} orderId - Order id
 * @return {string} Current state id
 */
const getOrderState = (orderId) =>
    psEval(`$o = new Order(${Number(orderId)}); echo (string) $o->current_state;`);

/**
 * Move an order to a state without going through the back office.
 *
 * This is the point of the auto-capture specs: capture must fire for any context
 * that changes an order, not only an admin click. Driving the change from the CLI
 * is what tells those two apart.
 *
 * @param {string|number} orderId - Order id
 * @param {string|number} stateId - Target state id
 */
const setOrderState = (orderId, stateId) =>
    psEval(
        `$o = new Order(${Number(orderId)});` +
            `$h = new OrderHistory();` +
            `$h->id_order = $o->id;` +
            `$h->changeIdOrderState(${Number(stateId)}, $o->id);` +
            `$h->addWithemail();`
    );

/**
 * Read recent MONEI log lines from the database.
 *
 * @param {number} limit - How many lines
 * @return {string} Tab separated rows
 */
const moneiLogs = (limit = 20) =>
    mysql(
        `SELECT severity, message FROM ps_log WHERE message LIKE '%MONEI%' ORDER BY id_log DESC LIMIT ${Number(limit)};`
    );

/**
 * Public URL of the ngrok tunnel the stack is running, if any.
 *
 * Read from the ngrok agent API over the compose network, from inside the
 * PrestaShop container, because the agent port is exposed to the network but not
 * published to the host.
 *
 * @return {string} Tunnel URL, empty when there is no tunnel
 */
const tunnelUrl = () => {
    try {
        const raw = exec(['sh', '-c', 'curl -sf http://ngrok:4040/api/tunnels']);
        const { tunnels = [] } = JSON.parse(raw);
        const https = tunnels.find((t) => (t.public_url || '').startsWith('https://'));

        return https ? https.public_url : '';
    } catch (error) {
        return '';
    }
};

/**
 * Point the store at the URL it is actually reachable on.
 *
 * ⚠️ A free ngrok tunnel gets a new hostname every time the agent reconnects, and
 * the store keeps serving the hostname it detected at boot. Once they diverge the
 * old URL 404s, every navigation fails, and the suite goes red in a way that looks
 * like the module broke — the handful of container fixtures keep passing, which
 * makes it more confusing, not less. Re-syncing on every seed keeps the two
 * together.
 *
 * @return {string} The domain now configured, empty when there is no tunnel
 */
const syncShopDomain = () => {
    const url = tunnelUrl();

    if (!url) {
        return '';
    }

    const { hostname } = new URL(url);

    // ⚠️ `ps_shop_url` is the one that matters. PrestaShop issues its canonical
    // redirect from that table, so updating only the PS_SHOP_DOMAIN configuration
    // leaves the store 302-ing every request to the previous, now dead, tunnel —
    // the new URL answers, then bounces to a 404 on the old one.
    mysql(
        `UPDATE ps_shop_url SET domain = '${hostname}', domain_ssl = '${hostname}' WHERE id_shop = 1;`
    );

    setConfig('PS_SHOP_DOMAIN', hostname);
    setConfig('PS_SHOP_DOMAIN_SSL', hostname);
    setConfig('PS_SSL_ENABLED', '1');
    console_(['cache:clear']);

    return hostname;
};

module.exports = {
    PS_FOLDER,
    deleteConfig,
    setInstalledVersion,
    unregisterHook,
    syncShopDomain,
    tunnelUrl,
    console: console_,
    exec,
    getConfig,
    getOrderState,
    installModule,
    installedVersion,
    isHookRegistered,
    moneiLogs,
    mysql,
    psEval,
    resetModule,
    setConfig,
    setOrderState,
    uninstallModule,
    upgradeModule,
};
