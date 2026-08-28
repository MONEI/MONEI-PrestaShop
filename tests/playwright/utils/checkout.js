const { expect } = require('@playwright/test');
const { fixture } = require('./fixtures');

/**
 * Products the specs shop with, recorded by the seed.
 */
const SIMPLE_PRODUCT_PATH = fixture(
    'simpleProductPath',
    'MONEI_E2E_PRODUCT_PATH',
    '/6-mug-the-best-is-yet-to-come.html'
);

/**
 * Delivery address the suite checks out with.
 *
 * Spain deliberately: Bizum is Spain-only and settles in EUR, so a Spanish
 * address is the one that exercises every MONEI method rather than only cards.
 * The seed enables the country and proves a carrier serves its zone.
 */
const ADDRESS = {
    firstName: 'Ada',
    lastName: 'Lovelace',
    address1: 'Calle Mayor 1',
    city: 'Madrid',
    postcode: '28013',
    phone: '600000000',
    country: 'Spain',
    // PrestaShop marks the identification number required for Spain and validates
    // its checksum, so this cannot be an arbitrary string. 12345678Z is the
    // canonical valid test DNI.
    dni: '12345678Z',
};

/**
 * MONEI test cards, mirroring `hosted-payment-service/playwright/fixtures.ts`.
 * Expiry 12/34 and CVC 123 apply to all of them.
 */
const CARDS = {
    challenge: '4444444444444406',
    direct: '4444444444444414',
    frictionless: '4444444444444422',
    frictionlessChallenge: '4444444444444430',
};

/**
 * Bizum test phone, from MONEI's own fixtures.
 */
const BIZUM_PHONE = '+34500000000';

/**
 * PayPal sandbox accounts, as used by MONEI's own suite and published at
 * https://docs.monei.com/testing. Do not invent new ones.
 */
const PAYPAL = {
    approve: { email: 'paypal-personal@monei.net', password: 'monei12345' },
    decline: { email: 'CCREJECT-REFUSED@paypal.com', password: 'PayPal2016' },
};

const CARD_EXPIRY = '12/34';
const CARD_CVC = '123';

/**
 * A unique guest email, so runs never collide on an existing customer.
 *
 * @return {string} Email address
 */
const guestEmail = () => `e2e-monei-${Date.now()}@example.com`;

/**
 * Put one product in the cart and open the checkout.
 *
 * @param {import('@playwright/test').Page} page        - Page
 * @param {string}                          productPath - Product URL path
 */
const addToCartAndCheckout = async (page, productPath = SIMPLE_PRODUCT_PATH) => {
    await page.goto(productPath, { waitUntil: 'domcontentloaded' });
    await page.locator('[data-button-action="add-to-cart"]').first().click();

    // The theme confirms the add through a modal rather than a navigation, so the
    // cart is only reliably populated once it has appeared.
    await expect(page.locator('#blockcart-modal, .cart-content')).toBeVisible({ timeout: 20000 });

    await page.goto('/order', { waitUntil: 'domcontentloaded' });
};

/**
 * Complete the personal information step as a guest.
 *
 * @param {import('@playwright/test').Page} page  - Page
 * @param {string}                          email - Guest email
 */
const fillPersonalInformation = async (page, email) => {
    await page.fill('#field-firstname', ADDRESS.firstName);
    await page.fill('#field-lastname', ADDRESS.lastName);
    await page.fill('#field-email', email);

    // Consent checkboxes come from optional modules (customer privacy, psgdpr), so
    // they are checked when present rather than assumed.
    for (const id of ['#field-customer_privacy', '#field-psgdpr']) {
        const box = page.locator(id);
        if ((await box.count()) && (await box.isVisible())) {
            await box.check();
        }
    }

    await page.locator('#checkout-personal-information-step [type="submit"]').first().click();
    await expect(page.locator('#checkout-addresses-step')).toHaveClass(/-current/, {
        timeout: 30000,
    });
};

/**
 * Complete the address step.
 *
 * ⚠️ The country is selected first, before any other field. Changing it re-renders
 * the address form over AJAX to pick up country specific fields, and that render
 * discards whatever was already typed. Filling address lines first and choosing
 * the country afterwards silently clears them, and the step then fails validation
 * on fields that visibly contain the right values a moment earlier.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const fillAddress = async (page) => {
    const country = page.locator('#field-id_country');

    await expect(country).toBeVisible({ timeout: 30000 });

    // The seed makes Spain the store default, so the form normally renders the
    // Spanish fields already. Only change it when it is not already selected —
    // selecting the value that is already active fires no change event, so the
    // form would never re-render and the wait below would time out on a form that
    // is in fact correct.
    const selected = await country
        .locator('option:checked')
        .innerText()
        .catch(() => '');

    if (!selected.trim().startsWith(ADDRESS.country)) {
        await country.selectOption({ label: ADDRESS.country });
    }

    await expect(page.locator('#field-dni')).toBeVisible({ timeout: 30000 });

    await page.fill('#field-address1', ADDRESS.address1);
    await page.fill('#field-city', ADDRESS.city);
    await page.fill('#field-postcode', ADDRESS.postcode);
    await page.fill('#field-dni', ADDRESS.dni);

    const phone = page.locator('#field-phone');
    if ((await phone.count()) && (await phone.isVisible())) {
        await phone.fill(ADDRESS.phone);
    }

    await page.locator('#checkout-addresses-step [type="submit"]').first().click();
    await expect(page.locator('#checkout-delivery-step')).toHaveClass(/-current/, {
        timeout: 30000,
    });
};

/**
 * Accept the offered carrier.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const chooseShipping = async (page) => {
    // A store with no carrier for the address renders this notice instead of any
    // option. Asserting it here turns a fixture problem into a clear message
    // rather than a timeout on the payment step.
    await expect(
        page.locator('#checkout-delivery-step .alert-danger'),
        'no carrier serves the delivery address; re-run the seed'
    ).toHaveCount(0);

    await page.locator('#checkout-delivery-step [type="submit"]').first().click();
    await expect(page.locator('#checkout-payment-step')).toHaveClass(/-current/, {
        timeout: 30000,
    });
};

/**
 * Drive a guest cart all the way to the payment step.
 *
 * @param {import('@playwright/test').Page} page        - Page
 * @param {string}                          productPath - Product URL path
 * @return {Promise<string>} The guest email used
 */
const goToPaymentStep = async (page, productPath = SIMPLE_PRODUCT_PATH) => {
    const email = guestEmail();

    await addToCartAndCheckout(page, productPath);
    await fillPersonalInformation(page, email);
    await fillAddress(page);
    await chooseShipping(page);

    return email;
};

/**
 * Test ids of the inputs MONEI renders inside its card iframe.
 */
const CARD_PART_TEST_ID = {
    number: 'card-number-input',
    expiry: 'expiry-date-input',
    cvc: 'cvc-input',
};

/**
 * Selectors for the MONEI component iframes.
 *
 * ⚠️ Matched on `title`, never on `src`. These are zoid iframes: the element is
 * created with an empty `src` and the real document is loaded internally, so the
 * frame reports a `js.monei.com/v2/inner-*` URL while the element attribute stays
 * empty. An `iframe[src*=...]` selector therefore matches nothing, and the failure
 * looks like the component never mounting.
 */
const COMPONENT_FRAME = {
    card: 'iframe[title="monei_card_input"]',
    // Split layout: the CardGroup renders one iframe per part, plus a controller
    // iframe for the group itself.
    cardNumber: 'iframe[title="monei_card_number"]',
    cardExpiry: 'iframe[title="monei_card_expiry"]',
    cardCvc: 'iframe[title="monei_card_cvc"]',
    bizum: 'iframe[title="monei_bizum_button"]',
    bizumDialog: 'iframe[title="monei_bizum"]',
    paypal: 'iframe[title="monei_paypal"]',
    // PayPal's own button, rendered by PayPal inside MONEI's component.
    // ⚠️ Scoped to the mount container and pinned to the first match. PayPal
    // re-renders its button, and for a moment two of these exist, which trips
    // Playwright's strict mode on an otherwise healthy page.
    paypalButton: '#monei-paypal-buttons-container iframe[title="PayPal"] >> nth=0',
};

/**
 * Containers the module mounts each MONEI component into.
 */
const MOUNT = {
    card: '#monei-card-buttons-container',
    bizum: '#monei-bizum-buttons-container',
    paypal: '#monei-paypal-buttons-container',
};

/**
 * Select a payment option by its visible label.
 *
 * @param {import('@playwright/test').Page} page  - Page
 * @param {RegExp}                          label - Label to match
 */
const selectPaymentOption = async (page, label) => {
    const option = page.locator('.payment-option').filter({ hasText: label }).first();

    await expect(option, `no payment option matching ${label}`).toBeVisible({ timeout: 30000 });
    await option.locator('input[type="radio"]').check();
};

/**
 * Fill the MONEI card iframe.
 *
 * The fields live in a cross origin zoid iframe served from js.monei.com, so they
 * are reached through the frame rather than the page. The frame is matched on its
 * URL because zoid's frame name is a base64 blob that changes every render.
 *
 * @param {import('@playwright/test').Page} page   - Page
 * @param {string}                          number - Card number
 */
const fillCard = async (page, number) => {
    // The cardholder name is a plain PrestaShop input rendered beside the fields
    // (onsite_card.tpl), not one of the hosted ones. Leaving it empty fails client
    // side with "Card holder name is not valid" and never reaches MONEI.
    await page.fill('#monei-card-holder-name', `${ADDRESS.firstName} ${ADDRESS.lastName}`);

    // Split is the default layout, so it is what is normally exercised here. Both
    // are supported because the single line layout stays available as an opt out.
    const isSplit = (await page.locator(COMPONENT_FRAME.cardNumber).count()) > 0;

    if (isSplit) {
        await page
            .frameLocator(COMPONENT_FRAME.cardNumber)
            .getByTestId(CARD_PART_TEST_ID.number)
            .fill(number);
        await page
            .frameLocator(COMPONENT_FRAME.cardExpiry)
            .getByTestId(CARD_PART_TEST_ID.expiry)
            .fill(CARD_EXPIRY);
        await page
            .frameLocator(COMPONENT_FRAME.cardCvc)
            .getByTestId(CARD_PART_TEST_ID.cvc)
            .fill(CARD_CVC);

        return;
    }

    const frame = page.frameLocator(COMPONENT_FRAME.card);

    await frame.getByTestId(CARD_PART_TEST_ID.number).fill(number);
    await frame.getByTestId(CARD_PART_TEST_ID.expiry).fill(CARD_EXPIRY);
    await frame.getByTestId(CARD_PART_TEST_ID.cvc).fill(CARD_CVC);
};

/**
 * Wait for the card fields to be ready, whichever layout is configured.
 *
 * @param {import('@playwright/test').Page} page - Page
 */
const expectCardFieldsReady = async (page) => {
    const split = page.locator(COMPONENT_FRAME.cardNumber);

    if (await split.count()) {
        await expect(split).toBeVisible({ timeout: 30000 });

        return;
    }

    await expect(page.locator(COMPONENT_FRAME.card)).toBeVisible({ timeout: 30000 });
};

/**
 * Accept the terms and submit the order.
 *
 * ⚠️ Clicks MONEI's own button, not PrestaShop's "Place Order". The module renders
 * through `displayPaymentByBinaries`, and PrestaShop keeps its own submit button
 * hidden for binary payment options, handing submission to the module's button
 * inside the payment section. Clicking the platform button times out on an
 * element that is present but never visible.
 *
 * @param {import('@playwright/test').Page} page   - Page
 * @param {string}                          method - Key of MOUNT to submit
 */
const acceptTerms = async (page) => {
    // ⚠️ Must happen before any MONEI component is opened, not merely before the
    // order is submitted. Every component gates on `moneiValidConditions()` in its
    // `onBeforeOpen`, which returns false while a required checkbox in
    // `#conditions-to-approve` is unchecked — the component then silently refuses
    // to open, with no error anywhere.
    const required = page.locator('#conditions-to-approve input[type="checkbox"][required]');

    for (let i = 0; i < (await required.count()); i += 1) {
        await required.nth(i).check();
    }
};

const placeOrder = async (page, method = 'card') => {
    await acceptTerms(page);

    await page.locator(`${MOUNT[method]} form button[type="submit"]`).click();
};

/**
 * Pay with Bizum.
 *
 * The Bizum component owns its own phone entry UI, so the flow is: accept terms,
 * click the rendered button, then fill the component's own fields.
 *
 * @param {import('@playwright/test').Page} page  - Page
 * @param {string}                          phone - Bizum phone
 */
const payWithBizum = async (page, phone = BIZUM_PHONE) => {
    await acceptTerms(page);
    await page.frameLocator(COMPONENT_FRAME.bizum).locator('#bizum_button').click();

    // Opening the button mounts a second, separate iframe carrying the phone entry
    // UI — `monei_bizum`, not the `monei_bizum_button` that was clicked.
    const dialog = page.frameLocator('iframe[title="monei_bizum"]');

    await dialog.getByTestId('bizum-phone-input').fill(phone);
    await dialog.getByTestId('bizum-pay-button').click();
};

/**
 * Clear the MONEI 3D Secure challenge simulator, when it appears.
 *
 * ⚠️ Always call this, even for the card documented as frictionless. Whether a
 * challenge appears depends on the account risk rules as well as the card, so a
 * spec that assumes a frictionless journey passes until the rules change and then
 * fails for reasons that have nothing to do with the code under test.
 *
 * The simulator may render in the page or inside a 3DS iframe, so both surfaces
 * are searched.
 *
 * @param {import('@playwright/test').Page} page    - Page
 * @param {'Complete'|'Fail'}               outcome - Outcome to simulate
 * @return {Promise<boolean>} Whether a challenge was handled
 */
const completeThreeDs = async (page, outcome = 'Complete') => {
    const deadline = Date.now() + 90000;
    let clicked = false;

    // Retries on effect, not on presence. Finding the control and clicking it once
    // is not enough: the challenge frame is still wiring itself up for a moment
    // after the control exists, so an early click lands on nothing and the
    // challenge stays put. The loop therefore keeps clicking until the browser
    // actually leaves the challenge, and re-clicking an already accepted challenge
    // is harmless.
    while (Date.now() < deadline) {
        if (/order-confirmation/.test(page.url())) {
            return clicked;
        }

        const frame = page.frames().find((f) => f.url().includes('secure.monei'));

        if (frame) {
            // No `exact`, matching MONEI's own suite
            // (hosted-payment-service/playwright/helpers.ts): the accessible name
            // carries surrounding whitespace, so an exact match finds nothing.
            const choice = frame.getByRole('button', { name: outcome });

            if (await choice.count().catch(() => 0)) {
                // Swallowed deliberately here, unlike a single shot click: the loop
                // exits on the browser leaving the challenge, so a failed attempt
                // is retried rather than being mistaken for success.
                await choice
                    .first()
                    .click({ timeout: 10000 })
                    .catch(() => {});
                clicked = true;
            }
        }

        await page.waitForTimeout(1000);
    }

    return clicked;
};

/**
 * Wait for the order confirmation page.
 *
 * ⚠️ Asserts the confirmation controller, not merely that the browser navigated.
 * A failed payment also navigates — back to the checkout with an error — so a
 * looser assertion would pass on a payment that never succeeded.
 *
 * @param {import('@playwright/test').Page} page - Page
 * @return {Promise<string>} Order reference
 */
const expectOrderConfirmation = async (page) => {
    await page.waitForURL(/order-confirmation/, { timeout: 120000 });

    // Asserts the confirmation itself, not merely that the browser navigated. A
    // failed payment also navigates — back to the checkout carrying an error — so
    // a URL check alone would pass on a payment that never succeeded.
    await expect(page.getByText(/your order is confirmed/i)).toBeVisible({ timeout: 30000 });

    // Read the rendered page text rather than an id. PrestaShop themes move the
    // order details block around, and a theme specific id is the first thing to
    // break on a store that is not the classic theme.
    const details = await page.locator('body').innerText();
    const reference = details.match(/order reference:\s*([A-Z0-9]+)/i);

    return {
        details,
        reference: reference ? reference[1] : '',
    };
};

/**
 * Approve a payment in the PayPal sandbox.
 *
 * Mirrors `performPayPalCheckout` in MONEI's own suite
 * (hosted-payment-service/playwright/helpers.ts).
 *
 * @param {import('@playwright/test').Page} popup   - The PayPal window
 * @param {{email: string, password: string}} account - Sandbox account
 */
const approveInPayPal = async (popup, account) => {
    const email = popup.locator('[name="login_email"], #email').first();

    await expect(email).toBeVisible({ timeout: 60000 });
    await email.fill(account.email);
    await popup
        .locator('[name="btnNext"], #btnNext')
        .first()
        .click()
        .catch(() => {});

    const password = popup.locator('[name="login_password"], #password').first();

    await expect(password).toBeVisible({ timeout: 60000 });
    await password.fill(account.password);
    await popup.locator('[name="btnLogin"], #btnLogin').first().click();

    const submit = popup.getByTestId('submit-button-initial');

    await expect(submit).toBeVisible({ timeout: 90000 });
    await submit.click();
};

/**
 * Pay with PayPal.
 *
 * @param {import('@playwright/test').Page} page    - Page
 * @param {{email: string, password: string}} account - Sandbox account
 */
const payWithPayPal = async (page, account = PAYPAL.approve) => {
    await acceptTerms(page);

    // The popup must be awaited alongside the click, not after it: PayPal opens
    // the window synchronously, so subscribing afterwards can miss the event.
    const [popup] = await Promise.all([
        page.waitForEvent('popup', { timeout: 60000 }),
        page
            .frameLocator(COMPONENT_FRAME.paypalButton)
            .locator('div[data-funding-source="paypal"]')
            .click(),
    ]);

    await approveInPayPal(popup, account);
};

module.exports = {
    BIZUM_PHONE,
    expectCardFieldsReady,
    approveInPayPal,
    payWithPayPal,
    acceptTerms,
    payWithBizum,
    CARD_PART_TEST_ID,
    PAYPAL,
    completeThreeDs,
    COMPONENT_FRAME,
    MOUNT,
    expectOrderConfirmation,
    fillCard,
    placeOrder,
    selectPaymentOption,
    ADDRESS,
    CARDS,
    CARD_CVC,
    CARD_EXPIRY,
    SIMPLE_PRODUCT_PATH,
    addToCartAndCheckout,
    chooseShipping,
    fillAddress,
    fillPersonalInformation,
    goToPaymentStep,
    guestEmail,
};
