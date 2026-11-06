const { mysql, psEval } = require('./ps-cli');

/**
 * Fixtures for the MONEI Customers API specs.
 *
 * MONEI calls go through psEval with a client built directly, never through
 * `service.monei`: that service needs the Doctrine repositories, which a CLI
 * bootstrap of config.inc.php does not provide.
 */

// Loading the module instance is what pulls in its vendor autoloader.
const CLIENT =
    "Module::getInstanceByName('monei'); " +
    "$c = \\PsMonei\\Service\\Monei\\MoneiClientFactory::create((string) Configuration::get('MONEI_TEST_API_KEY'), 'monei-e2e'); ";

/**
 * Hook tests run outside a request, so the context needs a shop of its own.
 */
const SHOP_CONTEXT =
    'Shop::setContext(Shop::CONTEXT_SHOP, 1); Context::getContext()->shop = new Shop(1); ';

/**
 * A password for a throwaway customer of the test store, made at run time so no
 * credential-like literal sits in the repository.
 */
const throwawayPassword = () => `e2e-${Math.random().toString(36).slice(2)}-Aa1!`;

const isUuid = (value) => /^[0-9a-f-]{20,}$/i.test(value);

/**
 * @param {number|string} idCustomer
 * @return {Array<{accountId: string, mode: string, moneiCustomerId: string}>}
 */
const mappingsFor = (idCustomer) => {
    const out = mysql(
        `SELECT account_id, mode, monei_customer_id FROM ps_monei2_customer WHERE id_customer = ${Number(idCustomer)} ORDER BY mode;`
    );

    return out
        ? out.split('\n').map((line) => {
              const [accountId, mode, moneiCustomerId] = line.split('\t');
              return { accountId, mode, moneiCustomerId };
          })
        : [];
};

const seedMapping = (idCustomer, accountId, mode, moneiCustomerId) =>
    mysql(
        `INSERT INTO ps_monei2_customer (id_customer, account_id, mode, monei_customer_id, date_add) ` +
            `VALUES (${Number(idCustomer)}, '${accountId}', '${mode}', '${moneiCustomerId}', NOW());`
    );

const seedCard = (idCustomer, token = 'tok_e2e_fake') =>
    mysql(
        `INSERT INTO ps_monei2_customer_card (id_customer, brand, country, last_four, expiration, tokenized, date_add) ` +
            `VALUES (${Number(idCustomer)}, 'visa', 'ES', '4242', 2000000000, '${token}', NOW()); SELECT LAST_INSERT_ID();`
    );

const cardsOf = (idCustomer) =>
    Number(
        mysql(
            `SELECT COUNT(*) FROM ps_monei2_customer_card WHERE id_customer = ${Number(idCustomer)};`
        )
    );

/**
 * A registered PrestaShop customer, created through the core API so the object
 * hooks behave as they would for a real one.
 */
const createPsCustomer = (email = `e2e-monei-cust-${Date.now()}@example.com`, isGuest = false) =>
    psEval(
        `${SHOP_CONTEXT}$cu = new Customer(); $cu->firstname = 'Ana'; $cu->lastname = 'Lopez'; ` +
            `$cu->email = '${email}'; $cu->passwd = Tools::hash('${throwawayPassword()}'); $cu->is_guest = ${isGuest ? 1 : 0}; ` +
            `$cu->id_shop = 1; $cu->add(); echo $cu->id;`
    );

/**
 * A real customer at MONEI, in the test account.
 */
const createMoneiCustomer = () =>
    psEval(
        `${CLIENT}$r = new \\Monei\\Model\\CreateCustomerRequest(); $r->setEmail('e2e-monei@example.com'); ` +
            `echo $c->customers->create($r)->getId();`
    );

/**
 * @return {number} HTTP status of GET /customers/:id (200 or the API error code)
 */
const moneiCustomerStatus = (moneiCustomerId) =>
    Number(
        psEval(
            `${CLIENT}try { $c->customers->get('${moneiCustomerId}'); echo 200; } ` +
                `catch (\\Monei\\ApiException $e) { echo $e->getCode(); }`
        )
    );

const deleteMoneiCustomer = (moneiCustomerId) =>
    psEval(
        `${CLIENT}try { $c->customers->delete('${moneiCustomerId}'); } catch (\\Throwable $e) {}`
    );

/**
 * @return {string[]} Payment method IDs saved under the customer at MONEI
 */
const moneiPaymentMethods = (moneiCustomerId) => {
    const out = psEval(
        `${CLIENT}foreach ($c->customers->listPaymentMethods('${moneiCustomerId}') as $m) { echo $m->getId(), "\\n"; }`
    );

    return out ? out.split('\n') : [];
};

const paymentCustomerId = (paymentId) =>
    psEval(`${CLIENT}echo (string) $c->payments->get('${paymentId}')->getCustomerId();`);

module.exports = {
    SHOP_CONTEXT,
    cardsOf,
    createMoneiCustomer,
    createPsCustomer,
    deleteMoneiCustomer,
    isUuid,
    mappingsFor,
    moneiCustomerStatus,
    moneiPaymentMethods,
    paymentCustomerId,
    seedCard,
    seedMapping,
    throwawayPassword,
};
