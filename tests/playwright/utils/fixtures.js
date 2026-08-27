const fs = require('fs');
const path = require('path');

/**
 * Where the seed records what it created.
 *
 * The seed cannot choose the ids PrestaShop hands out, so it writes them here and
 * the specs read them back. Gitignored: it describes one machine's store.
 */
const FIXTURES_FILE = path.resolve(__dirname, '../.fixtures.json');

/**
 * Read the seeded fixture ids, or nothing when the store was not seeded.
 *
 * @return {Object} Fixture values
 */
const readFixtures = () => {
    try {
        return JSON.parse(fs.readFileSync(FIXTURES_FILE, 'utf8'));
    } catch (error) {
        return {};
    }
};

/**
 * Write the fixture ids the seed created.
 *
 * @param {Object} values - Fixture values
 */
const writeFixtures = (values) =>
    fs.writeFileSync(FIXTURES_FILE, `${JSON.stringify(values, null, 4)}\n`);

/**
 * A fixture value, in the order that keeps every setup working.
 *
 * An environment variable always wins, so a one-off run can point at anything.
 * The seed file comes next. The fallback is what a stock Flashlight store ships
 * with, so a developer who has not seeded still gets a working run.
 *
 * @param {string} key      - Key in the seed file
 * @param {string} envName  - Environment variable that overrides it
 * @param {string} fallback - Value for an unseeded store
 * @return {string} Fixture value
 */
const fixture = (key, envName, fallback) =>
    process.env[envName] || readFixtures()[key] || fallback;

module.exports = { FIXTURES_FILE, fixture, readFixtures, writeFixtures };
