/**
 * Prepare a Flashlight store for the E2E suite.
 *
 * Configures the module with the MONEI test credentials, then records the ids of
 * the products the specs shop with. Run once after standing the stack up, and
 * again whenever the store is rebuilt.
 */
const { requireEnv } = require('./utils/env');
const {
    getConfig,
    installedVersion,
    mysql,
    psEval,
    resetModule,
    setConfig,
    syncShopDomain,
} = require('./utils/ps-cli');
const { FIXTURES_FILE, writeFixtures } = require('./utils/fixtures');

/**
 * Refuse anything that is not a MONEI test mode key.
 *
 * The suite pays on every run. A live key here would charge real money against a
 * real account, and nothing later in the run would notice, so this is checked
 * before a single setting is written.
 *
 * @param {string} apiKey - API key from the environment
 */
const assertTestKey = (apiKey) => {
    if (!apiKey.startsWith('pk_test_')) {
        throw new Error(
            'MONEI_TEST_API_KEY does not look like a test mode key (expected a ' +
                'pk_test_ prefix). The suite takes a payment on every run, so it ' +
                'refuses to seed against a live account.'
        );
    }
};

/**
 * Table prefix placeholder.
 *
 * The query is built in JavaScript but runs as a PHP string, where the prefix is
 * a constant rather than a literal. Writing `{P}` here and expanding it once keeps
 * the SQL readable instead of littering it with PHP concatenation.
 */
const P = '{P}';

/**
 * Find one product id matching a condition.
 *
 * @param {string} where - SQL condition against the product table, aliased `p`
 * @return {string} Product id, empty when nothing matched
 */
const findProduct = (where) => {
    const sql =
        `SELECT p.id_product FROM ${P}product p ` +
        `WHERE p.active = 1 AND ${where} ORDER BY p.id_product ASC`;
    const phpSql = sql.split(P).join("'._DB_PREFIX_.'");

    return psEval(`echo (string) Db::getInstance()->getValue('${phpSql}');`);
};

/**
 * Product link rewrite, which the storefront URL is built from.
 *
 * @param {string} productId - Product id
 * @return {string} Front office URL path
 */
const productPath = (productId) =>
    psEval(
        `$l = Context::getContext()->link->getProductLink(${Number(productId)});` +
            `echo (string) parse_url($l, PHP_URL_PATH);`
    );

/**
 * Make a country usable as a delivery address.
 *
 * A stock Flashlight store enables only France, the United Kingdom and the United
 * States, and the UK sits in a zone no active carrier serves — so the default
 * store country cannot actually be shipped to. A checkout spec that picks the
 * wrong country dies on "no carriers available for your delivery address", which
 * reads like a broken checkout rather than missing fixture data.
 *
 * Spain matters specifically: Bizum is Spain-only and settles in EUR, so it
 * cannot be exercised at all without this.
 *
 * @param {string} iso - Two letter country code
 * @return {{id: string, zone: string, carriers: string}} What was enabled
 */
const ensureShippableCountry = (iso) => {
    const row = mysql(
        `SELECT id_country, id_zone FROM ps_country WHERE iso_code = '${iso}' LIMIT 1;`
    );
    const [id, zone] = row.split('\t');

    if (!id) {
        throw new Error(`Country ${iso} is not present in the catalogue.`);
    }

    mysql(`UPDATE ps_country SET active = 1 WHERE id_country = ${Number(id)};`);

    // Enabling the country is not enough on its own: without a carrier serving
    // its zone the delivery step is a dead end.
    const carriers = mysql(
        `SELECT GROUP_CONCAT(c.name) FROM ps_carrier c ` +
            `JOIN ps_carrier_zone cz ON cz.id_carrier = c.id_carrier ` +
            `WHERE c.active = 1 AND c.deleted = 0 AND cz.id_zone = ${Number(zone)};`
    );

    if (!carriers || carriers === 'NULL') {
        throw new Error(
            `Country ${iso} is in zone ${zone}, which no active carrier serves. ` +
                'The delivery step would have no options.'
        );
    }

    return { id, zone, carriers };
};

const main = () => {
    const apiKey = requireEnv(
        'MONEI_TEST_API_KEY',
        'It must be the test mode API key of the MONEI account the suite pays with.'
    );
    assertTestKey(apiKey);

    const domain = syncShopDomain();
    if (domain) {
        process.stdout.write(`store domain synced to ${domain}\n`);
    }

    const version = installedVersion();
    if (!version) {
        throw new Error(
            'The monei module is not installed in the container. Bring the ' +
                'Flashlight stack up, then run: ' +
                'docker exec <container> php bin/console prestashop:module install monei'
        );
    }
    process.stdout.write(`monei module installed, version ${version}\n`);

    // ⚠️ Reset before writing credentials, never after. `prestashop:module reset`
    // is an uninstall followed by an install, and uninstall deletes every MONEI
    // Configuration key (monei.php:594-623). Resetting afterwards would silently
    // wipe the credentials this seed just wrote, leaving a store that looks
    // seeded and cannot take a payment.
    resetModule();

    setConfig('MONEI_TEST_API_KEY', apiKey);
    setConfig('MONEI_PRODUCTION_MODE', '0');

    // Required, not optional. Without an account id the module renders no payment
    // option at all: MONEI answers `400 Either accountId or paymentId must be
    // provided`, the method list comes back empty, and the checkout says "no
    // payment method available" with nothing in the log to explain it.
    setConfig(
        'MONEI_TEST_ACCOUNT_ID',
        requireEnv(
            'MONEI_TEST_ACCOUNT_ID',
            'It must be the account id the MONEI test API key belongs to.'
        )
    );

    // Prove the writes survived, rather than trusting that they happened.
    if (!getConfig('MONEI_TEST_ACCOUNT_ID')) {
        throw new Error('The MONEI account id did not persist.');
    }
    if (!getConfig('MONEI_TEST_API_KEY').startsWith('pk_test_')) {
        throw new Error(
            'The MONEI test API key did not persist. Something reset the module ' +
                'after the credentials were written.'
        );
    }

    // A product with combinations, one without, and a virtual one. Express
    // checkout resolves a variation before it can price anything, and a virtual
    // cart needs no shipping address at all, so both are journeys in their own
    // right rather than edge cases bolted onto the simple case.
    const hasCombination = `EXISTS (SELECT 1 FROM ${P}product_attribute pa WHERE pa.id_product = p.id_product)`;

    const variableProductId = findProduct(hasCombination);
    const simpleProductId = findProduct(`p.is_virtual = 0 AND NOT ${hasCombination}`);
    const virtualProductId = findProduct('p.is_virtual = 1');

    if (!simpleProductId) {
        throw new Error(
            'No simple active product found in the catalogue. The suite needs at ' +
                'least one product without combinations to shop with.'
        );
    }

    // A fresh install enables only cards. The baseline specs cover Bizum and
    // PayPal too, so those are turned on here rather than left to a manual step.
    ['MONEI_ALLOW_CARD', 'MONEI_ALLOW_BIZUM', 'MONEI_ALLOW_PAYPAL'].forEach((key) =>
        setConfig(key, '1')
    );

    // Spain for Bizum and for MONEI's primary market; France as the fallback that
    // a stock store already ships to.
    const spain = ensureShippableCountry('ES');
    process.stdout.write(`ES enabled (zone ${spain.zone}), carriers: ${spain.carriers}\n`);

    // Make Spain the store default so the guest address form renders Spanish
    // fields on first paint. Changing the country in the form re-renders it over
    // AJAX to pick up country specific fields, and racing that re-render is a
    // genuine source of flake: the identification number input does not exist yet,
    // and the step fails with the field simply absent. Defaulting the store
    // removes the race rather than papering over it with retries.
    setConfig('PS_COUNTRY_DEFAULT', spain.id);

    const fixtures = {
        countryIso: 'ES',
        countryId: spain.id,
        simpleProductId,
        simpleProductPath: productPath(simpleProductId),
        variableProductId: variableProductId || '',
        variableProductPath: variableProductId ? productPath(variableProductId) : '',
        virtualProductId: virtualProductId || '',
        virtualProductPath: virtualProductId ? productPath(virtualProductId) : '',
    };

    // Absent optional products are recorded empty rather than omitted, so a spec
    // can skip with a clear reason instead of failing on an undefined path.
    Object.entries(fixtures).forEach(([key, value]) => {
        if (!value) {
            process.stdout.write(`warning: no ${key} found; specs needing it will skip\n`);
        }
    });

    writeFixtures(fixtures);

    process.stdout.write(`wrote ${FIXTURES_FILE}\n`);
    process.stdout.write(`MONEI_PRODUCTION_MODE is ${getConfig('MONEI_PRODUCTION_MODE')}\n`);
};

main();
