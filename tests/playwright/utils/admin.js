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
        "\$employeeId = (int) Db::getInstance()->getValue("
            + "'SELECT id_employee FROM '._DB_PREFIX_.'employee WHERE email = \"' . pSQL('"
            + ADMIN_USER
            + "') . '\" LIMIT 1'"
            + ");"
            + "\$id = Tab::getIdFromClassName('AdminModules');"
            + "\$token = Tools::getAdminToken('AdminModules'.\$id.\$employeeId);"
            + "echo 'index.php?controller=AdminModules&configure=monei&token='.\$token;"
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

module.exports = { login, moduleConfigureUrl, openModuleConfiguration };
