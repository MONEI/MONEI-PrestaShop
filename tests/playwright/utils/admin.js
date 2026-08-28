const { expect } = require('@playwright/test');
const { ADMIN_PASSWORD, ADMIN_PATH, ADMIN_USER } = require('./env');
const { psEval } = require('./ps-cli');

/**
 * Log in to the back office.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const login = async (page) => {
    await page.goto(`${ADMIN_PATH}/`, { waitUntil: 'domcontentloaded' });

    // Already authenticated from an earlier navigation in the same context.
    if (!(await page.locator('#email').count())) {
        return;
    }

    await page.fill('#email', ADMIN_USER);
    await page.fill('#passwd', ADMIN_PASSWORD);
    await page.locator('#submit_login').click();
    await expect(page.locator('body')).toBeVisible();
};

/**
 * URL of the module configuration screen.
 *
 * The back office builds its links with a per-employee security token, so the
 * link has to come from PrestaShop rather than being assembled here.
 *
 * @return {string} Absolute configuration URL
 */
const moduleConfigureUrl = () =>
    psEval(
        // ⚠️ The token is derived from the employee id, so it must be the employee
        // the browser actually signs in as. A CLI bootstrap has no employee in
        // context, and a token computed for "no employee" is simply rejected as
        // invalid.
        "$employeeId = (int) Db::getInstance()->getValue("
            + "'SELECT id_employee FROM '._DB_PREFIX_.'employee WHERE email = \"' . pSQL('"
            + ADMIN_USER
            + "') . '\" LIMIT 1'"
            + ");"
            + "$id = Tab::getIdFromClassName('AdminModules');"
            + "$token = Tools::getAdminToken('AdminModules'.$id.$employeeId);"
            + "echo 'index.php?controller=AdminModules&configure=monei&token='.$token;"
    );

/**
 * Open the MONEI module configuration screen.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const openModuleConfiguration = async (page) => {
    await login(page);
    // networkidle rather than domcontentloaded: the configuration screen finishes
    // assembling its panels after the initial document.
    await page.goto(`${ADMIN_PATH}/${moduleConfigureUrl()}`, { waitUntil: 'networkidle' });
};

/**
 * Move an order to a new state through the back office.
 *
 * ⚠️ Uses PrestaShop 9's Symfony route directly, carrying the session `_token`
 * read from the URL after login. The legacy `AdminOrders&vieworder` URL redirects
 * to that route while rebuilding it as http://, because the tunnel terminates TLS
 * and forwards plain HTTP, so PrestaShop cannot tell the request was HTTPS. The
 * secure session cookie is dropped on that hop and the browser lands on the login
 * page instead of the order.
 *
 * ⚠️ Driven through the back office rather than the CLI because a PrestaShop CLI
 * bootstrap has no Symfony container, so the module's services cannot be built.
 * That the hook is not gated on admin context is covered by it being registered
 * unconditionally, which upgrade.spec.js asserts.
 *
 * @param {import('@playwright/test').Page} page      - Page
 * @param {string|number}                   orderId   - Order id
 * @param {string}                          stateName - Visible name of the target state
 */
const setOrderStateViaBackOffice = async (page, orderId, stateName) => {
    await login(page);

    const token = new URL(page.url()).searchParams.get('_token');

    await page.goto(
        `${ADMIN_PATH}/index.php/sell/orders/${Number(orderId)}/view?_token=${token}`,
        { waitUntil: 'networkidle' }
    );

    const status = page.locator('#update_order_status_action_input');

    await expect(status).toBeVisible({ timeout: 30000 });
    await status.selectOption({ label: stateName });
    await page.locator('#update_order_status_action_btn').click();
    await page.waitForLoadState('networkidle');
};

module.exports = {
    login,
    moduleConfigureUrl,
    openModuleConfiguration,
    setOrderStateViaBackOffice,
};
