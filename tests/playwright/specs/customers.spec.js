const { test, expect } = require('../utils/test');
const { baseUrl, supportsThreeDs, THREE_DS_SKIP_REASON } = require('../utils/env');
const { getConfig, mysql, psEval, setConfig } = require('../utils/ps-cli');
const {
    ADDRESS,
    CARDS,
    acceptConsents,
    addToCartAndCheckout,
    chooseShipping,
    completeThreeDs,
    expectCardFieldsReady,
    expectOrderConfirmation,
    fillAddress,
    fillCard,
    placeOrder,
    selectPaymentOption,
} = require('../utils/checkout');
const {
    SHOP_CONTEXT,
    throwawayPassword,
    cardsOf,
    createMoneiCustomer,
    createPsCustomer,
    isUuid,
    mappingsFor,
    moneiCustomerStatus,
    moneiPaymentMethods,
    paymentCustomerId,
    seedCard,
    seedMapping,
} = require('../utils/customers');

/**
 * MONEI Customers API: payments of registered buyers are linked to a MONEI
 * customer, and deleting a card or a buyer here deletes it at MONEI.
 *
 * Contract: docs/plans/20261105-monei-customers-api-contract.md
 */

const CARDS_URL = '/index.php?fc=module&module=monei&controller=customerCards';

const customerIdByEmail = (email) =>
    Number(
        mysql(
            `SELECT id_customer FROM ps_customer WHERE email = '${email}' ORDER BY id_customer DESC LIMIT 1;`
        )
    );

const deleteCardRequest = (page, params) =>
    page.request.post(
        `${baseUrl()}${CARDS_URL}&${new URLSearchParams({
            action: 'deleteCustomerCard',
            ajax: '1',
            ...params,
        })}`
    );

test.describe('customers: registered buyer', () => {
    test.describe.configure({ mode: 'serial' });

    let tokenize;
    let page;
    let email;
    let idCustomer;
    let moneiCustomerId;
    let token;

    test.beforeAll(async ({ browser }) => {
        tokenize = getConfig('MONEI_TOKENIZE');
        setConfig('MONEI_TOKENIZE', '1');
        page = await browser.newPage({ baseURL: baseUrl() });
    });

    test.afterAll(async () => {
        setConfig('MONEI_TOKENIZE', tokenize);
        await page.close();
    });

    test('a card payment that saves the card is linked to a MONEI customer', async () => {
        test.skip(!supportsThreeDs(), THREE_DS_SKIP_REASON);

        email = `e2e-monei-reg-${Date.now()}@example.com`;
        await addToCartAndCheckout(page);

        // A password at the personal information step registers the buyer and logs
        // them in, which is what makes them linkable (a guest never is).
        await page.fill('#field-firstname', ADDRESS.firstName);
        await page.fill('#field-lastname', ADDRESS.lastName);
        await page.fill('#field-email', email);
        // Hummingbird hides the field behind a checkbox; classic shows it. The sign-in
        // tab carries a second #field-password, for an existing account.
        const createAccount = page.getByRole('checkbox', { name: /create an account/i });
        if (await createAccount.count()) {
            await createAccount.check();
        }
        const password = page.locator('#field-password[autocomplete="new-password"]');
        await expect(password).toBeVisible();
        await password.fill(throwawayPassword());
        await acceptConsents(page);
        await page.locator('#checkout-personal-information-step [type="submit"]').first().click();
        await expect(page.locator('#checkout-addresses-step')).toHaveClass(/-current/, {
            timeout: 30000,
        });

        await fillAddress(page);
        await chooseShipping(page);
        await selectPaymentOption(page, /credit card/i);
        await expectCardFieldsReady(page);
        // After January 2038: the expiry is stored as a Unix timestamp, which a
        // signed INT column cannot hold, and the failed card save broke the order.
        await fillCard(page, CARDS.direct, '05/45');
        await page.locator('#monei-tokenize-card').check();
        await placeOrder(page);
        await completeThreeDs(page);
        await expectOrderConfirmation(page);

        idCustomer = customerIdByEmail(email);
        const mappings = mappingsFor(idCustomer);

        expect(mappings).toHaveLength(1);
        expect(mappings[0].accountId).toBe(getConfig('MONEI_TEST_ACCOUNT_ID'));
        expect(mappings[0].mode).toBe('test');
        expect(isUuid(mappings[0].moneiCustomerId)).toBe(true);
        moneiCustomerId = mappings[0].moneiCustomerId;

        const idPayment = mysql(
            `SELECT p.id_payment FROM ps_monei2_payment p JOIN ps_cart c ON c.id_cart = p.id_cart ` +
                `WHERE c.id_customer = ${idCustomer} ORDER BY p.date_add DESC LIMIT 1;`
        );
        expect(paymentCustomerId(idPayment)).toBe(moneiCustomerId);

        // The card is saved locally once MONEI reports the payment, and under the
        // customer at MONEI with the same ID as its token.
        await expect.poll(() => cardsOf(idCustomer), { timeout: 60000 }).toBe(1);
        token = mysql(
            `SELECT tokenized FROM ps_monei2_customer_card WHERE id_customer = ${idCustomer};`
        );
        expect(moneiPaymentMethods(moneiCustomerId)).toContain(token);
    });

    test('a card delete without a valid token is refused', async () => {
        test.skip(!token, 'needs the saved card from the previous test');

        const idCard = mysql(
            `SELECT id_customer_card FROM ps_monei2_customer_card WHERE id_customer = ${idCustomer};`
        );
        await page.goto(CARDS_URL);
        const staticToken = await page.evaluate(() => window.prestashop.static_token);

        const otherCustomer = createPsCustomer();
        const otherCard = seedCard(otherCustomer);

        for (const params of [
            { customerCardId: idCard },
            { customerCardId: idCard, token: 'not-the-token' },
            { customerCardId: idCard, 'token[]': staticToken },
            // Ownership: the attacker's own valid token on someone else's card.
            { customerCardId: otherCard, token: staticToken },
        ]) {
            await deleteCardRequest(page, params);
        }

        expect(cardsOf(idCustomer)).toBe(1);
        expect(cardsOf(otherCustomer)).toBe(1);
        expect(moneiPaymentMethods(moneiCustomerId)).toContain(token);
    });

    test('deleting the saved card in My Account deletes it at MONEI', async () => {
        test.skip(!token, 'needs the saved card from the previous test');

        // A second card, to prove the modal is usable again without a reload.
        const secondCard = seedCard(idCustomer, 'tok_e2e_second');
        const realCard = mysql(
            `SELECT id_customer_card FROM ps_monei2_customer_card WHERE id_customer = ${idCustomer} AND tokenized = '${token}';`
        );

        await page.goto(CARDS_URL);
        await page.locator(`.delete-card-btn[data-customer-card-id="${realCard}"]`).click();
        await page.locator('#confirmDeleteCard').click();

        await expect.poll(() => cardsOf(idCustomer), { timeout: 30000 }).toBe(1);
        expect(moneiPaymentMethods(moneiCustomerId)).not.toContain(token);
        // The customer stays linked: only the card went.
        expect(mappingsFor(idCustomer)).toHaveLength(1);

        await expect(page.locator('#deleteCardModal')).toBeHidden();
        await page.locator(`.delete-card-btn[data-customer-card-id="${secondCard}"]`).click();
        await expect(page.locator('#confirmDeleteCard')).toBeEnabled();
        await page.locator('#confirmDeleteCard').click();
        await expect.poll(() => cardsOf(idCustomer), { timeout: 30000 }).toBe(0);
    });
});

test.describe('customers: deleting the buyer', () => {
    const accountId = () => getConfig('MONEI_TEST_ACCOUNT_ID');

    const linkedCustomer = (email) => {
        const idCustomer = createPsCustomer(email);
        const moneiCustomerId = createMoneiCustomer();
        seedMapping(idCustomer, accountId(), 'test', moneiCustomerId);
        seedCard(idCustomer);

        return { idCustomer, moneiCustomerId };
    };

    test('a customer delete deletes the MONEI customer and the saved cards', async () => {
        const { idCustomer, moneiCustomerId } = linkedCustomer();
        expect(moneiCustomerStatus(moneiCustomerId)).toBe(200);

        psEval(`${SHOP_CONTEXT}(new Customer(${idCustomer}))->delete();`);

        expect(mappingsFor(idCustomer)).toEqual([]);
        expect(cardsOf(idCustomer)).toBe(0);
        expect(moneiCustomerStatus(moneiCustomerId)).toBe(404);
    });

    test('the back-office soft delete erases, and an ordinary update does not', async () => {
        const { idCustomer } = linkedCustomer();

        psEval(
            `${SHOP_CONTEXT}$cu = new Customer(${idCustomer}); $cu->deleted = false; $cu->update();`
        );
        expect(mappingsFor(idCustomer)).toHaveLength(1);
        expect(cardsOf(idCustomer)).toBe(1);

        // What DeleteCustomerHandler does with "do not allow registration again".
        psEval(
            `${SHOP_CONTEXT}$cu = new Customer(${idCustomer}); $cu->deleted = true; $cu->update();`
        );
        expect(mappingsFor(idCustomer)).toEqual([]);
        expect(cardsOf(idCustomer)).toBe(0);
    });

    test('a psgdpr erasure by ID erases and reports success', async () => {
        const { idCustomer } = linkedCustomer();

        // psgdpr 2.x shape: the ID as a string in a list.
        const result = psEval(
            `${SHOP_CONTEXT}echo json_encode(Hook::exec('actionDeleteGDPRCustomer', ['${idCustomer}'], null, true));`
        );

        expect(mappingsFor(idCustomer)).toEqual([]);
        expect(cardsOf(idCustomer)).toBe(0);
        expect(JSON.parse(result).monei).toBe(JSON.stringify(true));
    });

    test('a psgdpr erasure by email erases registered and guest accounts with that email', async () => {
        const email = `e2e-monei-gdpr-${Date.now()}@example.com`;
        const registered = linkedCustomer(email);
        const guest = createPsCustomer(email, true);
        seedCard(guest);

        psEval(
            `${SHOP_CONTEXT}Hook::exec('actionDeleteGDPRCustomer', [['email' => '${email}']], null, true);`
        );

        expect(mappingsFor(registered.idCustomer)).toEqual([]);
        expect(cardsOf(registered.idCustomer)).toBe(0);
        expect(cardsOf(guest)).toBe(0);
        expect(moneiCustomerStatus(registered.moneiCustomerId)).toBe(404);
    });
});

test.describe('customers: mapping store', () => {
    // The unit suite runs on an in-memory fake; this is the real SQL.
    const store = (code) =>
        psEval(
            `Module::getInstanceByName('monei'); $s = new \\PsMonei\\Repository\\CustomerMappingStore(); ${code}`
        );

    test('keeps modes apart, keeps the first ID on a duplicate, and deletes only what it is told', async () => {
        const id = createPsCustomer();

        store(`$s->insertMapping(${id}, 'acc_same', 'live', 'cus_live');`);
        store(`$s->insertMapping(${id}, 'acc_same', 'test', 'cus_first');`);
        expect(store(`echo $s->findMapping(${id}, 'acc_same', 'live');`)).toBe('cus_live');

        // INSERT IGNORE: a lost race must not overwrite the winner, and still reports success.
        expect(
            store(`var_export($s->insertMapping(${id}, 'acc_same', 'test', 'cus_second'));`)
        ).toBe('true');
        expect(store(`echo $s->findMapping(${id}, 'acc_same', 'test');`)).toBe('cus_first');

        store(`$s->deleteMappingIfHolds(${id}, 'acc_same', 'test', 'cus_other');`);
        expect(store(`echo $s->findMapping(${id}, 'acc_same', 'test');`)).toBe('cus_first');
        store(`$s->deleteMappingIfHolds(${id}, 'acc_same', 'test', 'cus_first');`);
        expect(store(`var_export($s->findMapping(${id}, 'acc_same', 'test'));`)).toBe('NULL');

        // Db::insert() does not escape, so a quote would break the insert.
        expect(
            store(`var_export($s->insertMapping(${id}, "acc'quote", 'test', 'cus_quote'));`)
        ).toBe('true');
        expect(store(`echo $s->findMapping(${id}, "acc'quote", 'test');`)).toBe('cus_quote');

        store(`$s->insertMapping(${id}, 'acc_same', 'test', 'cus_third');`);
        store(`$s->deleteMapping(${id}, 'acc_same', 'test');`);
        expect(store(`echo $s->findMapping(${id}, 'acc_same', 'live');`)).toBe('cus_live');
    });
});
