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

    // ⚠️ Wait for the sign-in to actually land. Asserting on `body` alone returns
    // while the login POST is still in flight, and the caller's next goto then
    // supersedes it — the navigation fails with net::ERR_ABORTED and the request
    // that does go through carries no session, so the back office answers
    // "Invalid security token". That reads as a bad token rather than a race.
    await Promise.all([
        page.waitForURL((url) => !/controller=AdminLogin/i.test(url.toString()), {
            timeout: 60000,
        }),
        page.locator('#submit_login').click(),
    ]);
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
        // ⚠️ No LIMIT 1 in this query. Db::getRow appends ' LIMIT 1'
        // unconditionally on 1.7.8, so a query that already ends in one becomes
        // `LIMIT 1 LIMIT 1` — a syntax error. getValue then answers false, the
        // employee id reads as 0, and the token computed for "no employee" is
        // rejected as invalid. The back office reports that as a bad token, which
        // sends you looking at the token instead of the query.
        '$employeeId = (int) Db::getInstance()->getValue(' +
            "'SELECT id_employee FROM '._DB_PREFIX_.'employee WHERE email = \"' . pSQL('" +
            ADMIN_USER +
            "') . '\"'" +
            ');' +
            "$id = Tab::getIdFromClassName('AdminModules');" +
            "$token = Tools::getAdminToken('AdminModules'.$id.$employeeId);" +
            "echo 'index.php?controller=AdminModules&configure=monei&token='.$token;"
    );

/**
 * Open the MONEI module configuration screen.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const openModuleConfiguration = async (page) => {
    await login(page);
    // ⚠️ Not networkidle. The PrestaShop 9 back office keeps connections open, so
    // it never goes idle and the navigation times out on a page that loaded fine.
    // Wait for the module's own form instead, which is the thing being used.
    await page.goto(`${ADMIN_PATH}/${moduleConfigureUrl()}`, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#module_form, form[name="module_form"]').first()).toBeAttached({
        timeout: 60000,
    });
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

    // ⚠️ The Symfony CSRF token is not in the URL after signing in here: 1.7.8
    // lands on the legacy AdminDashboard controller, whose URL carries the legacy
    // `token` instead. Every Symfony link in the back office carries the same
    // session-wide `_token`, so take it from one of those and fall back to the URL
    // for a back office that does put it there.
    const tokenHref = await page
        .locator('a[href*="_token="]')
        .first()
        .getAttribute('href')
        .catch(() => null);

    const token = tokenHref
        ? new URL(tokenHref, page.url()).searchParams.get('_token')
        : new URL(page.url()).searchParams.get('_token');

    if (!token) {
        throw new Error('Could not find the back office CSRF token after signing in.');
    }

    // ⚠️ Not networkidle, here or below: the PrestaShop 9 back office keeps
    // connections open and never goes idle, so waiting on it times out a page that
    // loaded fine. Wait for the control being used instead.
    await page.goto(`${ADMIN_PATH}/index.php/sell/orders/${Number(orderId)}/view?_token=${token}`, {
        waitUntil: 'domcontentloaded',
    });

    const status = page.locator('#update_order_status_action_input');

    await expect(status).toBeVisible({ timeout: 60000 });
    await status.selectOption({ label: stateName });
    await page.locator('#update_order_status_action_btn').click();

    // The status change reloads the order page; the updated status is what says
    // it landed.
    await expect(page.locator('#update_order_status_action_input')).toBeVisible({
        timeout: 60000,
    });
};

module.exports = {
    login,
    moduleConfigureUrl,
    openModuleConfiguration,
    setOrderStateViaBackOffice,
};
