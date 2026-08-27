# Express Checkout, CardGroup, and monei.js v3 for PrestaShop

> Revision 3. Revised after two plan-review passes. See "Corrections from review" for what changed and why.

## Overview

Bring the MONEI PrestaShop module (v2.0.18) to parity with MONEI WooCommerce 7.2.4, and build the missing engineering around it.

Delivers:
- **monei.js v3** — prerequisite for everything else.
- **Express checkout** — Apple Pay / Google Pay / PayPal buttons on product, cart, and checkout pages, with per-location and per-method merchant toggles.
- **Split card fields (CardGroup)** — the new default card layout, with an opt-out back to single-line CardInput.
- **Pre-authorization correctness** — auto-capture on order status change, which the module does not have today.
- **Playwright e2e suite** — none exists.
- **CI** — no test workflow exists.
- **Docs, changelog, release.**

Problem solved: PrestaShop merchants currently get a materially weaker MONEI integration than WooCommerce merchants. No express checkout means no one-tap wallet purchase from product or cart. No auto-capture means merchants who drive fulfilment from an ERP, cron, or the webservice API never capture their pre-authorizations, and the money silently expires while the order reads as paid.

## Corrections from review

Revision 1 contained four material errors. Recorded here so they are not reintroduced.

1. **All monei.js integration is inline in a Smarty template, not in `views/js/front/`.** `views/js/front/front.js` is 56 lines and contains zero monei API calls. Every call site is in `views/templates/hook/displayPaymentByBinaries.tpl` (617 lines): `monei.confirmPayment` :282, `monei.Bizum` :383, `monei.CardInput` :441, `monei.createToken` :481, `monei.PaymentRequest` :513 and :542, `monei.PayPal` :613. This changes the target of every JS task and adds an extraction task (Task 4).
2. **The `$hasUnsupportedMethod` "bug" was a misdiagnosis.** `MoneiService.php:702` reads what `MoneiService.php:672` set — `setAllowedPaymentMethods([$mappedMethod])`, a **single-element** array, and only for `['multibanco','mbway','paypal','bizum']`. `MoneiService.php:440` is a different method (`getPaymentMethodsAllowed()`) building from `MONEI_ALLOW_*` config keys for rendering payment options. Two unrelated lists. The `:702` check is **correct**: pick MB WAY → `['mbway']` → intersect non-empty → stays `SALE`, which is right, because MB WAY cannot authorize. Do not remove it. The real defect is elsewhere — see Task 7.
3. **No upgrade script.** `registerHook()` in `install()` runs only on fresh install. The repo has an established `upgrade/` pattern (10 scripts; `upgrade-2.0.3.php` seeds a config default, `upgrade-2.0.10.php` handles order states). Without an upgrade script every merchant upgrading from 2.0.18 gets no new hooks and no config defaults — express would not appear and the CardGroup default would not apply. Task 3 fixes this.
4. **Auto-capture would have clobbered the merchant's status change.** `AdminMoneiCapturePaymentController.php:129-137` calls `capturePayment()` then `setCurrentState(MONEI_STATUS_SUCCEEDED)`. Reusing that path from a status hook resets "Shipped" back to "Payment accepted" and re-fires `actionOrderStatusPostUpdate`. Task 8 handles this.

## Context (from discovery)

- **Module**: `monei.php` extends `PaymentModule`, v2.0.18, `ps_versions_compliancy` min 8. Namespace `PsMonei`, PSR-4 from `/src`, PrestaShop DI container.
- **monei.js**: v2 only, loaded at `monei.php:2195`, and **only** when `page_name == 'checkout'`. Express needs it on product and cart pages too.
- **monei.js call sites**: all inline in `views/templates/hook/displayPaymentByBinaries.tpl`. See Corrections #1.
- **Registered hooks** (`monei.php:99-110`): `actionFrontControllerSetMedia`, `displayCustomerAccount`, `actionDeleteGDPRCustomer`, `actionExportGDPRData`, `displayBackOfficeHeader`, `displayAdminOrder`, `displayPaymentByBinaries`, `paymentOptions`, `displayPaymentReturn`, `actionCustomerLogoutAfter`, `moduleRoutes`, `actionOrderSlipAdd`, `actionGetAdminOrderButtons`. Plus `actionGenerateDocumentReference`, registered **conditionally** on PS 8.1+ at `monei.php:113-118`, with an Order override fallback on 8.0.x. No product-page, cart-page, or order-status hook.
- **Configuration forms**: HelperForm arrays in `monei.php` — `renderForm()` :1188, `renderFormGateways()` :1381, `renderFormStatus()` :1616, `renderFormComponentStyle()` :1754. Defaults :1120-1188. `getContent()` :758 assembles `helper_form_1..4` into `views/templates/admin/configure.tpl`. Existing style keys `MONEI_PAYMENT_REQUEST_STYLE` and `MONEI_PAYPAL_STYLE` are set at `monei.php:90-91` and JSON-validated in `configure.tpl:219-234`.
- **Front controllers**: `applepay`, `cardlogos`, `confirmation`, `createPayment`, `customerCards`, `errors`, `redirect`, `validation`.
- **CSRF convention**: `controllers/front/createPayment.php:135-143` — `isAuthorizedRequest($data)` reads `php://input` and compares `$data['token'] === Tools::getToken(false)`. Reuse this, do not invent a second scheme.
- **Templates**: `views/templates/front/` and `views/templates/hook/`.
- **Order states**: module already creates a custom `MONEI_STATUS_AUTHORIZED` state at install (`monei.php:454-458`).
- **Upgrade**: `upgrade/` holds 10 scripts. Established pattern for adding hooks and config defaults to existing installs.
- **Uninstall**: `monei.php:594-623` deletes every config key by name, one line each. New keys must be added there.
- **Release ZIP**: `.github/workflows/release.yml:24-26` — `cp -r .` then `zip -r monei.zip monei -x '*.git*' -x '/build/*' -x 'monei/output.log'`. Note `-x '/build/*'` never matches, because paths inside the ZIP are `monei/build/*`. That is exactly why the `output.log` fix in commit `4df9fef` needed the `monei/` prefix.
- **Tests**: `phpunit.xml` exists, `tests/` does not. **CI**: only `release.yml`.
- **Translations**: `/translations/` holds ~25 locale files keyed `$_MODULE['<{monei}prestashop>...']`.
- **Dev env**: PrestaShop Flashlight Docker. Cache-clear plus `prestashop:module reset monei` are mandatory after every change — see module `CLAUDE.md`. Module logs live in the `ps_log` DB table, not log files.

### Pre-authorization audit (completed — do not redo)

Checked against the three bugs found in WooCommerce. **None of the three transfers.**

| WooCommerce bug | PrestaShop finding |
|---|---|
| Capture hook registered for admin requests only | **Different.** PrestaShop has *no* automatic capture on any order status transition. Verified: grep for `actionOrderStatus` across the module returns nothing. Capture is manual only → `controllers/admin/AdminMoneiCapturePaymentController.php:127`. Addressed by Task 8. |
| Apple Pay / Google Pay never pre-authorized | **Not present.** Verified via the `:672` → `:702` path. Wallets never set `allowedPaymentMethods`, so they reach `AUTH` normally. No action. |
| Capture cleared its marker but never persisted it | **Not present.** `src/Service/Monei/MoneiService.php:828` persists via `moneiPaymentRepository->save()`. No action. |

**Actual defect found**, addressed by Task 7: at `MoneiService.php:440`, when `MONEI_PAYMENT_ACTION === 'auth'`, MB WAY and Multibanco are stripped from `getPaymentMethodsAllowed()`. That list feeds `PaymentOptionService.php:128`, which renders the payment options. So an auth-mode merchant **silently loses MB WAY and Multibanco from checkout entirely** — the methods do not appear at all. Decided: the hiding behaviour is kept deliberately, but the merchant must be warned in settings rather than losing the methods silently. Task 7 implements the warning.

### Reference implementations (WooCommerce — read, do not re-derive)

Base path `/Users/dmitriy/Work/woocommerce/wp-content/plugins/MONEI-WooCommerce/`:

- `assets/js/monei-cc-classic.js` — **the CardGroup reference.** `monei.CardInput` :229, `monei.CardGroup` :274. The split layout is one CardGroup carrying payment details plus three separate fields. This proves the v3 API by shipping code, not by a CDN grep.
- `src/Services/express/ExpressCheckoutAssets.php` (474 lines) — asset loading, gating, button styles, location resolution.
- `src/Services/express/ExpressCheckoutAjaxHandler.php` (1497 lines) — 9 endpoints. We port 5. See Technical Details.
- `src/Services/express/ExpressCartBackup.php` (358 lines) — product-page express replaces the cart and must restore it.
- `assets/js/monei-block-express-checkout.js` (519 lines) — the client, including failure handling.
- `tests/playwright/` — 7 specs, and `utils/` with `checkout.js`, `env.js`, `fixtures.js`, `paypal.js`, `wp-cli.js`.

### Stripe PrestaShop reference (capture model)

`/Users/dmitriy/Work/stripe-prestashop/stripe_official.php:1064` — `hookActionOrderStatusUpdate`. The established PrestaShop pattern: a comma-separated list of trigger status IDs (`STRIPE_CAPTURE_STATUS`), guarded by a dedicated waiting state (`STRIPE_CAPTURE_WAITING`). We adopt that shape. We do **not** port its `STRIPE_CAPTURE_EXPIRE` tracker this round.

## Development Approach

- **Testing approach**: **Regular** (code first, then tests), e2e-weighted.
  - PHPUnit covers pure logic only — minor-unit conversion, address normalization, method gating, capture eligibility. Testable without booting PrestaShop.
  - Playwright carries the real coverage. Express and CardGroup are browser behaviour; that is where they break.
  - Rationale: PrestaShop controllers and hooks are tightly coupled to the framework. Unit-testing them in isolation costs more than it returns.
- **The Playwright harness lands first (Task 1), not last.** Revision 1 deferred it and had seven tasks knowingly shipping failing specs, which contradicted the "all tests pass before the next task" gate and the user's zero-tolerance rule. The harness is config plus utils, not feature work. Only `paypal.js` lands late (Task 16), immediately before the first task that needs it.
- **JS tooling**: vanilla JavaScript retained. `package.json` at repo root with ESLint + Prettier only, **no bundler** — the module `CLAUDE.md` states the `views/js/_dev/` pipeline is deprecated. Do not revive it.
- **Branch and PR**: one feature branch, one PR. Conventional commits enforced by commitlint — never `--no-verify`. Do not push to `master`.
- **CRITICAL: every task MUST include new or updated tests** for the code it changes. Cover success and error paths.
- **CRITICAL: all tests must pass before starting the next task.** No exceptions, no "pre-existing", no "unrelated". With the harness at Task 1, this gate is now actually enforceable at every step.
- **CRITICAL: update this plan file when scope changes during implementation.**
- After every module change, run the Flashlight cache-clear and module-reset from `CLAUDE.md`, then hard-refresh. Changes do not take effect otherwise.

## Testing Strategy

- **Unit tests (PHPUnit)**: `tests/Unit/`. Required for every task adding pure logic.
- **E2E tests (Playwright)**: `tests/playwright/specs/`. Required for every task changing UI or the contract behind it. Same rigour as unit tests.
- Note `.php-cs-fixer.php` runs `->in(__DIR__)` and excludes only `build, config, translations, files, node_modules, vendor`. New `tests/` PHP **will** be style-checked — write it clean or the CI dry-run in Task 18 fails.

## Constraints and known traps

Requirements, not advice. These cost real time in the WooCommerce round.

1. **Express failures must surface on every surface.** Ownership of a failure follows "did this button start the payment", **never** the platform's notion of the active payment method. The worst WooCommerce bug of the round: the Cart block never marked the express method active, so a rejected order was discarded in silence — the shopper sat on a cart that had already taken their PayPal approval, with nothing on screen.
2. **Capture windows are per payment method** — cards 7 days, Bizum 30. Source of truth is the OpenAPI spec at `https://js.monei.com/api/v1/openapi.json`, **not** any plugin's settings copy. The WooCommerce settings description was wrong about which methods support pre-auth, and that error was propagated into docs before being caught.
3. **PayPal returns a partial address** — name, email, country, no street — when the PayPal account has no address saved. Confirmed an account property, not a `requestShipping` flag; tested both ways. PrestaShop `Address` validation is strict and will reject it.
4. **CSS: no `em` units for wallet button height.** Parent font-size varies by theme, so `3.125em` is not 50px. Use `px`. And **do not put `display:flex` on the wallet container** — it breaks the PayPal button width.
5. **PayPal sandbox automation**: the button is a cross-origin zoid iframe appended to `<body>`. The login is a popup *or* an in-page overlay depending on headless/headed, and the overlay starts as `about:blank`. Racing a popup event against a frame locator produces failures that look like PayPal being broken. Poll both surfaces and accept only a frame that actually reached paypal.com.
6. **Verify behaviour, not markers.** Grepping a CDN bundle or reading a settings description misled us twice in the WooCommerce round. A background task can also report exit code 0 while the command failed — parse the output, do not trust the code.
7. **Never commit credentials.** MONEI test key and account ID go in a gitignored `.env`. Not in any committed file, PR body, or docs page. PayPal sandbox accounts are published at `https://docs.monei.com/testing` — reference them, do not invent new ones.

## Solution Overview

**Architecture**: follow the module's existing service-container pattern. Express becomes services in `src/Service/Express/`, registered in `config/front/services.yml`, reached through one new front controller dispatching on an `action` parameter.

**Key design decisions**:

1. **Extract the inline JS from `displayPaymentByBinaries.tpl` before touching it (Task 4).** 617 lines of Smarty with embedded JavaScript cannot be linted, cannot be unit-tested, and is where CardGroup, monei.js v3, and express all have to land. Smarty values move to `Media::addJsDef`. This is a scope increase over revision 1 and is accepted deliberately: without it, Task 2's ESLint and Task 15's "pass ESLint clean" are meaningless.
2. **One express front controller, action-dispatched.** Nine PrestaShop controllers would be nine files of boilerplate. One `express.php` keeps it to one route and one token check.
3. **Seven express endpoints, not WooCommerce's nine.** Only `normalizeAddress` and `clearCart` are dropped. `normalizeAddress` exists in WooCommerce solely because Blocks pushes addresses into the Store API cart (`monei-block-express-checkout.js:170`) — no PrestaShop analogue; it is server-internal work during `createOrder`, and exposing it is needless attack surface. `clearCart` overlaps cart-backup restore.
   `bootstrap` and `getCartDetails` are **kept**: they are load-bearing at mount. `monei-block-express-checkout.js:349-351` awaits both, then feeds `sessionId` and `cart.amount` straight into `createExpressComponent` — the component cannot be built without them. `getCartDetails` also returns the line-item breakdown (subtotal, discount, fees, shipping, tax — `ExpressCheckoutAjaxHandler.php:948-980`) that Apple Pay and Google Pay sheets render; without it the wallet sheet shows a bare total. And `bootstrap` forces the session open, which is exactly the mechanism that makes an anonymous product-page visitor work.
4. **Reuse the existing style config keys.** `MONEI_PAYMENT_REQUEST_STYLE` and `MONEI_PAYPAL_STYLE` already exist with a form and JSON validation. A third express-only style key would be a parallel build.
5. **Cart backup, not a parallel cart.** Product-page express must not destroy a shopper's real cart. Snapshot, swap, restore — including on failure and abandonment.
6. **CardGroup as the default, with an opt-out.** `MONEI_CARD_LAYOUT`, default `split`. This changes checkout appearance for every existing merchant on upgrade, so it ships with a changelog warning and a one-setting revert. WooCommerce will move to the same default, so this is a shared direction, not a divergence.
7. **Auto-capture via `actionOrderStatusPostUpdate`, registered unconditionally.** Deviates from Stripe's `actionOrderStatusUpdate`, which fires before the transition commits. Neither hook can block a transition, so this is purely about ordering. Registered for all contexts — the WooCommerce admin-only bug is what this avoids. The hook path must **not** write the order status afterwards. Manual admin button stays as override.
8. **No bundler.** See Development Approach.

## Technical Details

### New configuration keys

| Key | Type | Default | Purpose |
|---|---|---|---|
| `MONEI_CARD_LAYOUT` | string | `split` | `split` (CardGroup) or `single` (CardInput) |
| `MONEI_EXPRESS_ENABLED` | bool | `0` | Master switch for express checkout |
| `MONEI_EXPRESS_LOCATIONS` | csv | `product,cart,checkout` | Where buttons render |
| `MONEI_EXPRESS_METHODS` | csv | `applePay,googlePay,paypal` | Which methods render |
| `MONEI_CAPTURE_STATUS` | csv | `''` (empty = off) | Order status IDs that trigger auto-capture |

Five keys, not six — button style reuses the existing keys per design decision 4.

Express defaults to **off**: it changes the storefront, so merchants opt in. Auto-capture defaults to **off** for the same reason. CardGroup defaults to **on** — that is the deliberate breaking change.

**Relationship to the existing `MONEI_ALLOW_*` keys must be explicit** (`monei.php:604-612`): `MONEI_EXPRESS_METHODS` is an *intersection*, never an override. A method disabled in `MONEI_ALLOW_PAYPAL` must not appear as an express button regardless of `MONEI_EXPRESS_METHODS`. Task 11 enforces this.

**Multistore**: the module uses `Configuration::updateValue`/`get` shop-agnostically everywhere except order states (`monei.php:426-434`). The new keys match that existing convention deliberately, not by omission (Rule 10). Revisit only if a multistore bug is reported.

**GDPR**: `hookActionExportGDPRData` (`monei.php:2315`) exports only `Monei2CustomerCard`. Express must store no new personal data in `monei2_*` tables — wallet addresses go straight into PrestaShop's own `Address`/`Customer` records, which the platform already covers. Task 13 must hold to that; if it cannot, the GDPR hooks need extending and this plan needs updating.

### Express endpoints

One controller, `controllers/front/express.php`, dispatching on `action`: `bootstrap`, `getCartDetails`, `getSelectedProductData`, `addToCart`, `getShippingOptions`, `updateShippingMethod`, `createOrder`.

`getCartDetails` is the amount/currency/line-items/shippingRequired source for **every** surface. The product-page flow below is not the only flow: on cart and checkout there is no `getSelectedProductData`, so without `getCartDetails` nothing yields the amount. Rendering the amount server-side into the container does not survive the cart page's AJAX quantity updates or an `updateShippingMethod` round trip.

`addToCart` and `updateShippingMethod` must both **return the recomputed totals payload**, or the wallet sheet goes stale mid-flow.

Every action reuses the `Tools::getToken(false)` check from `createPayment.php:135-143` and returns JSON. Failures return structured `{code, message}` so the client can surface them (constraint 1).

A standard front controller is reachable without a `moduleRoutes` entry — `hookModuleRoutes()` (`monei.php:2339-2353`) exists solely for the Apple Pay `.well-known` path. Do not add a route unless a friendly URL is actually wanted; if one is, `Tools::generateHtaccess()` must run on upgrade, as the module already does in `reset()`/`enable()` at :651/:676.

### Hook placement

Express needs hooks the module does not register. Candidates, **to be verified empirically against the PS 8 classic theme in Flashlight** — do not assume from names (constraint 6):

- Product page: `displayProductAdditionalInfo`.
- Cart page: `displayShoppingCartFooter`.
- Checkout: `displayPaymentByBinaries` is registered but renders *inside* the payment options list. Express belongs above it.

**Checkout deduplication is required.** `displayPaymentByBinaries.tpl` already renders `monei.PaymentRequest` (Apple/Google Pay, :513 and :542) and `monei.PayPal` (:613). Adding an express block above the options would put two Apple Pay buttons on one page with different code paths and different failure handling. Task 14 must define which one wins.

### Processing flow (product-page express)

1. Shopper taps the wallet button on a product page.
2. `getSelectedProductData` → resolve variation, quantity, price.
3. `addToCart` → server snapshots the existing cart, swaps in the express item.
4. monei.js `PaymentRequest` opens the wallet sheet.
5. Address change → `getShippingOptions`; method change → `updateShippingMethod`.
6. Wallet authorizes → `createOrder` with the payment token and the wallet address payload.
7. Server normalizes the address (constraint 3), creates the order, confirms the payment.
8. Success → redirect to confirmation, then restore the snapshot cart. **Any** failure → restore the snapshot cart *and* surface the error on the originating surface (constraint 1).

## Progress Tracking

- Mark completed items `[x]` immediately when done — do not batch.
- Add newly discovered tasks with a ➕ prefix.
- Document issues and blockers with a ⚠️ prefix.
- Update this plan if implementation deviates from scope.

## What Goes Where

- **Implementation Steps** (`[ ]`): everything achievable in this codebase.
- **Post-Completion** (no checkboxes): external action — the docs site PR, manual device testing, the release.

## Implementation Steps

### Task 1: Playwright harness

**Files:**
- Create: `tests/playwright/playwright.config.js`
- Create: `tests/playwright/utils/env.js`
- Create: `tests/playwright/utils/fixtures.js`
- Create: `tests/playwright/utils/checkout.js`
- Create: `tests/playwright/utils/ps-cli.js`
- Create: `tests/playwright/seed.js`
- Create: `tests/playwright/README.md`
- Create: `tests/playwright/.env.example`
- Modify: `.gitignore`
- Create: `package.json`

- [ ] port the WooCommerce Playwright config and utils, adapting to Flashlight Docker
- [ ] write `utils/ps-cli.js` wrapping `docker exec ... bin/console` for module reset, cache clear, config set, **and installing a prior module version plus running `prestashop:module upgrade`** — Task 3's upgrade spec cannot run without that last capability. Replaces the WooCommerce `wp-cli.js`.
- [ ] write `seed.js` creating test products: a simple product, a variable product, and a virtual product
- [ ] add `.env.example` and gitignore the real `.env` — **never commit the MONEI test key or account ID** (constraint 7)
- [ ] document setup in `README.md`, referencing the published PayPal sandbox accounts at `https://docs.monei.com/testing`
- [ ] create root `package.json` with `@playwright/test` as a devDependency and a `test:e2e` script; state in it which package manager the root uses and why it is not in `build/` (`build/package.json` already exists with `packageManager: yarn@4.9.4`)
- [ ] write one smoke spec that loads the storefront and proves the harness runs
- [ ] **write the pre-refactor baseline specs now**, against the unmodified template: card, Bizum, and PayPal checkout end to end. Task 4 refactors a live payment path, and "provably inert" is unprovable without a spec that was green beforehand.
- [ ] run the smoke and baseline specs — must pass before Task 2

### Task 2: JS linting tooling

**Files:**
- Modify: `package.json`
- Create: `eslint.config.js`
- Create: `.prettierrc`
- Create: `.prettierignore`

- [ ] add ESLint + Prettier devDependencies, no bundler
- [ ] configure ESLint for browser globals and the module's vanilla style
- [ ] add `lint` and `format` scripts
- [ ] ignore `vendor/`, `views/js/jquery.json-viewer.js`, `build/`, `node_modules/`
- [ ] run lint on the existing front JS and fix only genuine errors, not style churn (Rule 3)
- [ ] run lint clean — must pass before Task 3

### Task 3: Configuration keys, upgrade script, uninstall cleanup

**Files:**
- Modify: `monei.php`
- Create: `upgrade/upgrade-2.1.0.php`

- [ ] add install defaults for all five new keys in `monei.php` (`MONEI_CARD_LAYOUT` = `split`, express off, `MONEI_CAPTURE_STATUS` empty)
- [ ] **first**, verify empirically in Flashlight which PS 8 classic-theme hooks render where express buttons belong — candidates `displayProductAdditionalInfo` (product) and `displayShoppingCartFooter` (cart). This verification cannot wait for Task 14: `install()` and the upgrade script must name the real hooks, and both land here.
- [ ] register the three new hooks in `install()`: `actionOrderStatusPostUpdate`, plus the two hooks just verified
- [ ] create `upgrade/upgrade-2.1.0.php` following `upgrade-2.0.3.php` (config defaults) and `upgrade-2.0.10.php` (order states) — it **must** register the three new hooks and seed all five keys, or existing merchants get none of this feature
- [ ] add all five keys to the uninstall cleanup at `monei.php:594-623`
- [ ] **bump `$this->version` in `monei.php` to `2.1.0`.** `upgrade-2.1.0.php` only fires when the module version reaches 2.1.0, so without this the upgrade script can never run — including in this task's own test. Note `build/package.json`'s release-it bumper also writes `../monei.php` at release time; the bump here must not fight it.
- [ ] record that `MONEI_CAPTURE_STATUS` stores **per-install** order status IDs. `monei.php:582-591` deletes MONEI order states when unused on uninstall, which reissues IDs on reinstall, leaving a stale `MONEI_CAPTURE_STATUS` pointing at the wrong statuses. Either clear the key on uninstall or validate the IDs on read.
- [ ] write a Playwright spec that upgrades a 2.0.18 install and asserts the hooks are registered and the defaults seeded (needs the module-upgrade capability added to `ps-cli.js` in Task 1)
- [ ] run tests — must pass before Task 4

### Task 4: Extract inline JS from displayPaymentByBinaries.tpl

**Files:**
- Modify: `views/templates/hook/displayPaymentByBinaries.tpl`
- Create: `views/js/front/payment.js`
- Modify: `monei.php`

- [ ] **understand the real structure before touching it.** This is a behavioural rewrite of ~260 lines, not a file move. The template holds **six** `<script>` blocks: one unconditional at :1-354, then five inside a single `{foreach from=$paymentMethodsToDisplay}` at :355-618 — Bizum :373, card :411, googlePay :504, applePay :533, paypal :570 — selected by `{if}/{elseif}` on `$paymentOptionName`. Each closes over container markup created in the same loop iteration.
- [ ] convert the Smarty `{if}/{elseif}` dispatch into runtime dispatch over a `paymentMethodsToDisplay` array, and rewrite the five closures into five init functions that locate their containers by ID or data attribute
- [ ] pass every Smarty-interpolated value through `Media::addJsDef` or data attributes — nothing may stay interpolated into JS. Coupling is low: eight values (`moneiAccountId`, `moneiAmount`, `moneiAmountFormatted`, `moneiCurrency`, `moneiToken`, `moneiPaymentAction`, `moneiCreatePaymentUrlController`, `paymentOptionName`), all assigned at `monei.php:2012-2024`. The `{l s='Pay'}` at :364 is markup, not JS.
- [ ] **verify `addJsDef` timing explicitly — this is the most likely way this task silently breaks.** `registerJavascript` for `payment.js` runs in `hookActionFrontControllerSetMedia` (`monei.php:2185`), but the eight values only exist inside `hookDisplayPaymentByBinaries` (`monei.php:2011-2026`), which runs later during content render. Confirm the defs still land in the footer `js_def` block ahead of the deferred script.
- [ ] **make `payment.js` no-op when the hook did not render.** It now loads on every checkout page, but `hookDisplayPaymentByBinaries` early-returns at `monei.php:1991-2000` when MONEI is unavailable or no binary methods exist. Guard on the presence of the defs.
- [ ] keep the template to markup and containers only
- [ ] register the new file in `hookActionFrontControllerSetMedia`
- [ ] make the extracted file pass ESLint clean
- [ ] **verify behaviour is unchanged**: card, Bizum, Apple Pay, Google Pay, and PayPal each still complete a payment in Flashlight. This is a pure refactor and must be provably inert (constraint 6).
- [ ] re-run the Task 1 baseline specs (card, Bizum, PayPal) — they were green before the refactor, so any failure here is this task's doing
- [ ] run tests — must pass before Task 5

### Task 5: Upgrade monei.js v2 → v3 and widen asset loading

**Files:**
- Modify: `monei.php`
- Modify: `views/js/front/payment.js`

- [ ] change the URL at `monei.php:2195` to `https://js.monei.com/v3/monei.js`
- [ ] audit the extracted `payment.js` against v3 for all six APIs in use: `CardInput`, `PaymentRequest`, `PayPal`, `Bizum`, `createToken`, `confirmPayment`. Cross-check each against the WooCommerce v3 usage, which is shipping and proven.
- [ ] extend `hookActionFrontControllerSetMedia` to load monei.js on `product` and `cart` page names, gated on `MONEI_EXPRESS_ENABLED`
- [ ] **verify each payment method still completes end to end on v3** — behaviour, not markers (constraint 6)
- [ ] re-run the baseline specs (card, Bizum, PayPal) against v3, and add Apple Pay / Google Pay specs
- [ ] run tests — must pass before Task 6

### Task 6: PHPUnit harness

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/Unit/SmokeTest.php`
- Modify: `phpunit.xml`
- Modify: `composer.json`

- [ ] create `tests/bootstrap.php` loading the Composer autoloader without booting PrestaShop
- [ ] point `phpunit.xml` at `tests/Unit` and the new bootstrap
- [ ] add a `test` script to `composer.json`
- [ ] write a smoke test proving the harness runs
- [ ] confirm `./vendor/bin/php-cs-fixer fix --dry-run` passes on the new `tests/` PHP — the fixer's finder includes it
- [ ] run `composer test` — must pass before Task 7

### Task 7: Warn when auth mode removes MB WAY / Multibanco from checkout

**Files:**
- Modify: `src/Service/Monei/MoneiService.php`
- Create: `tests/Unit/Service/PaymentMethodsAllowedTest.php`

- [ ] **do not touch `MoneiService.php:702`.** It is correct — see Corrections #2. Add a short comment recording why, so it is not "cleaned up" later.
- [ ] confirm the defect at `MoneiService.php:440`: with `MONEI_PAYMENT_ACTION === 'auth'`, MB WAY and Multibanco are stripped from `getPaymentMethodsAllowed()`, which feeds `PaymentOptionService.php:128`, so they vanish from checkout entirely
- [ ] **decided: keep the hiding behaviour, but warn the merchant.** The filtering stays. What changes is that the merchant is told, instead of losing two enabled payment methods silently (Rule 11).
- [ ] add a warning shown when `MONEI_PAYMENT_ACTION === 'auth'` and either `MONEI_ALLOW_MBWAY` or `MONEI_ALLOW_MULTIBANCO` is on, naming the methods that auth mode removes from checkout
- [ ] **place the warning on both forms.** `MONEI_PAYMENT_ACTION` lives in `renderForm()` (field at `monei.php:1334`); `MONEI_ALLOW_MBWAY`/`MONEI_ALLOW_MULTIBANCO` live in `renderFormGateways()` (`monei.php:1381`). A merchant who flips payment action to `auth` without revisiting the gateways form would otherwise see the warning only on the page they did not change.
- [ ] derive the "does not support AUTH" method list from the OpenAPI spec, not from a hardcoded copy that can drift (constraint 2)
- [ ] extract the method-gating decision into a pure, testable function
- [ ] write unit tests: auth mode with MB WAY enabled, auth mode without, sale mode with MB WAY, all methods disabled, empty config
- [ ] write unit tests for the warning trigger: shown in auth+mbway, auth+multibanco, both; not shown in sale mode or when neither is enabled
- [ ] write a Playwright spec asserting the warning appears in settings and the methods are indeed absent from checkout in auth mode
- [ ] run tests — must pass before Task 8

### Task 8: Auto-capture on order status change

**Files:**
- Modify: `monei.php`
- Create: `tests/Unit/Service/CaptureTriggerTest.php`

- [ ] implement `hookActionOrderStatusPostUpdate` — registered unconditionally in Task 3, never gated on admin context (the WooCommerce bug-1 lesson)
- [ ] keep the trigger logic small: is the order's module `monei`, and is the new status ID in `MONEI_CAPTURE_STATUS`. Do **not** add a service class — `MoneiService::capturePayment` already enforces the real guards at `:793` (already captured → `PAYMENT_ALREADY_CAPTURED`) and `:797` (not authorized → `PAYMENT_NOT_AUTHORIZED`). Wrap the call in `try/catch (MoneiException)`. (Rule 2)
- [ ] **do not write the order status after capturing.** `AdminMoneiCapturePaymentController.php:129-137` does that, which from a status hook would reset the merchant's "Shipped" back to "Payment accepted" and re-fire this hook. The hook path captures only.
- [ ] add a reentrancy guard so a nested status change cannot loop
- [ ] specify the capture amount explicitly: the authorized amount from the MONEI payment record, not the order total, which a merchant may have edited. `capturePayment` rejects amounts above the authorized figure at `:805`.
- [ ] log every capture failure to `ps_log` and record it against the order — never fail silently (Rule 11)
- [ ] **name the mechanism before writing it.** `CLAUDE.md` documents a `monei2_admin_order_message` table that **does not exist**: `src/Entity/` holds only `Monei2CustomerCard`, `Monei2History`, `Monei2Payment`, `Monei2Refund`, and `sql/` has no such schema. Use `Monei2History` or PrestaShop's `CustomerMessage`/`$order->note`. Do not invent a table mid-task. The stale `CLAUDE.md` schema list is fixed in Task 20.
- [ ] write unit tests: status matches, does not match, order not MONEI, empty config, already-captured exception swallowed correctly
- [ ] write a Playwright spec asserting a **CLI-driven** status change captures and that the target status survives the capture
- [ ] run tests — must pass before Task 9

### Task 9: Configuration UI

**Files:**
- Modify: `monei.php`
- Modify: `views/templates/admin/configure.tpl`

- [ ] add `MONEI_CARD_LAYOUT` to the appropriate HelperForm — the forms are PHP arrays in `monei.php`, not template files
- [ ] add `MONEI_CAPTURE_STATUS` to `renderFormStatus()` (`monei.php:1616`), which already renders `MONEI_STATUS_AUTHORIZED` at :1694
- [ ] add a new express form with `MONEI_EXPRESS_ENABLED`, `MONEI_EXPRESS_LOCATIONS`, `MONEI_EXPRESS_METHODS`; wire it into `getContent()` (:758) and `configure.tpl` as `helper_form_5`
- [ ] reuse `MONEI_PAYMENT_REQUEST_STYLE` and `MONEI_PAYPAL_STYLE` for express button styling — do not add a third style key
- [ ] describe pre-authorization support accurately, deriving supported methods from the OpenAPI spec, not the WooCommerce settings copy, which was wrong (constraint 2)
- [ ] write a Playwright spec asserting settings persist and take effect
- [ ] run tests — must pass before Task 10

### Task 10: CardGroup split card fields as the new default

**Files:**
- Modify: `views/js/front/payment.js`
- Modify: `views/templates/hook/displayPaymentByBinaries.tpl`
- Create: `views/css/monei-card.css`

- [ ] implement the split layout following `monei-cc-classic.js:274` — one `monei.CardGroup` carrying payment details plus three separate fields
- [ ] branch on `MONEI_CARD_LAYOUT`: `split` → CardGroup, `single` → the existing `CardInput` path
- [ ] add the container markup for the split fields to the template
- [ ] style split fields to match the PrestaShop classic theme form controls
- [ ] verify error and validation states render per individual field, not only on the group
- [ ] confirm the saved-card flow in `customerCards.js` still works under both layouts
- [ ] write Playwright specs: split-field payment success, split-field decline, and the `single` opt-out still rendering CardInput
- [ ] run tests — must pass before Task 11

### Task 11: Express method resolution

**Files:**
- Create: `src/Service/Express/ExpressMethodResolver.php`
- Modify: `config/front/services.yml`
- Create: `tests/Unit/Service/ExpressMethodResolverTest.php`

- [ ] **first verify what "account gating" in `ExpressCheckoutAssets.php:342 account_offers` actually gates** — MONEI-account method availability, or WordPress user-account/guest-checkout requirements. Building the wrong reading is the risk here (constraint 6).
- [ ] resolve the effective method list as an intersection: `MONEI_EXPRESS_METHODS` ∩ `MONEI_ALLOW_*` ∩ what the MONEI account offers. A method off in `MONEI_ALLOW_PAYPAL` must never render as an express button.
- [ ] reuse the existing payment-methods cache in `MoneiService` rather than adding a second API call path (Rule 7)
- [ ] resolve per location, so a method can be enabled on cart but not product
- [ ] write unit tests for each intersection arm: merchant off, account does not offer, `MONEI_ALLOW_*` off, all on, none on, per-location differences
- [ ] run tests — must pass before Task 12

### Task 12: Express cart backup

**Files:**
- Create: `src/Service/Express/ExpressCartBackup.php`
- Modify: `config/front/services.yml`
- Create: `tests/Unit/Service/ExpressCartBackupTest.php`

- [ ] port the WooCommerce `ExpressCartBackup.php` approach to PrestaShop's `Cart` object
- [ ] snapshot the shopper's cart before a product-page express purchase swaps it out
- [ ] restore on success, on failure, and on abandonment — a lost cart is a lost customer
- [ ] scope the snapshot to the customer session and handle the guest case
- [ ] write unit tests: snapshot/restore round trip, restore on failure, empty original cart, guest session
- [ ] run tests — must pass before Task 13

### Task 13: Express front controller

**Files:**
- Create: `controllers/front/express.php`
- Create: `src/Service/Express/ExpressOrderBuilder.php`
- Modify: `config/front/services.yml`
- Create: `tests/Unit/Service/ExpressOrderBuilderTest.php`

- [ ] **resolve guest express checkout first.** A logged-out shopper tapping Apple Pay on a product page needs either `PS_GUEST_CHECKOUT` enabled or a `Customer` created from the wallet payload. This is a functional prerequisite, not an edge case, and no other task owns it.
- [ ] create `controllers/front/express.php` dispatching on `action`
- [ ] implement `getSelectedProductData`, `addToCart`, `getShippingOptions`, `updateShippingMethod`, `createOrder`
- [ ] **reuse the existing CSRF convention** — the `isAuthorizedRequest`/`Tools::getToken(false)` pattern at `createPayment.php:135-143`. Do not invent a second scheme (Rule 7).
- [ ] verify `Tools::getToken(false)` is actually valid for an anonymous visitor on a product page; it is cookie-derived and currently only exercised from checkout
- [ ] verify the submitted amount against the server-computed amount before creating an order, mirroring `ExpressCheckoutAjaxHandler.php:692` — never trust a client-supplied total
- [ ] **reuse `OrderService` for order creation.** The module's existing path is `ValidationModuleFrontController` → `OrderService`. `ExpressOrderBuilder` must delegate to it, not call `validateOrder()` itself. This is the single largest place a parallel build could appear (Rule 7).
- [ ] express orders do not tokenize: pass `$tokenizeCard = false` and `$cardTokenId = 0` to `createMoneiPayment`, matching the signature used at `createPayment.php:36-41`. A wallet payment has no card to save.
- [ ] **define webhook convergence.** `CheckModuleFrontController` handles async status updates, so an express order confirmed client-side will also receive a webhook. State how both paths converge on the same `monei2_payment` record without double-processing.
- [ ] implement `bootstrap` and `getCartDetails`; `bootstrap` forces the session/cart open for an anonymous visitor and issues the session id that the other actions validate against
- [ ] return structured `{code, message}` JSON on every failure so the client can surface it (constraint 1)
- [ ] store no new personal data in `monei2_*` tables — wallet addresses go into PrestaShop's own `Address`/`Customer` records, which the GDPR hooks already cover
- [ ] write unit tests for the pure helpers: minor-unit conversion, amount matching, quantity and variation resolution
- [ ] write a Playwright spec for the endpoint contract, including a rejected bad token and a mismatched amount
- [ ] run tests — must pass before Task 14

### Task 14: Express button rendering

**Files:**
- Modify: `monei.php`
- Create: `views/templates/hook/expressCheckout.tpl`
- Create: `views/css/monei-express.css`

- [ ] **verify empirically in Flashlight** which PS 8 classic-theme hooks render where express buttons belong — do not assume from names (constraint 6)
- [ ] implement the product-page hook (candidate: `displayProductAdditionalInfo`)
- [ ] implement the cart-page hook (candidate: `displayShoppingCartFooter`)
- [ ] **define checkout deduplication**: `displayPaymentByBinaries.tpl` already renders `PaymentRequest` at :513/:542 and `PayPal` at :613. Either express-on-checkout suppresses those, or the checkout express block reuses that existing wiring. Two Apple Pay buttons with different failure paths on one page is not acceptable.
- [ ] render the container only when express is enabled, the location is enabled, and `ExpressMethodResolver` returns at least one method
- [ ] write `monei-express.css` using **`px` for button height, never `em`** (constraint 4)
- [ ] **do not set `display:flex` on the wallet container** — it breaks PayPal button width (constraint 4)
- [ ] write a Playwright spec asserting buttons render on each enabled location, are absent on disabled ones, and are not duplicated at checkout
- [ ] run tests — must pass before Task 15

### Task 15: Express client JavaScript

**Files:**
- Create: `views/js/front/express.js`
- Modify: `monei.php`

- [ ] port `monei-block-express-checkout.js` to vanilla JS against the five endpoints
- [ ] wire monei.js v3 `PaymentRequest` for Apple Pay and Google Pay, and the PayPal component
- [ ] handle shipping address and shipping method change events
- [ ] **surface every failure on the surface that started it** — ownership follows "did this button start the payment", never the platform's notion of the active method (constraint 1). This was the worst bug of the WooCommerce round; it must not repeat.
- [ ] restore the backed-up cart on every failure path, including shopper abandonment
- [ ] pass every user-facing string in via `Media::addJsDef` — `$this->l()` does not reach a static `.js` file
- [ ] pass ESLint clean
- [ ] write a Playwright spec for a failed express order asserting the error is visible to the shopper and the original cart is restored
- [ ] run tests — must pass before Task 16

### Task 16: PayPal express and partial-address handling

**Files:**
- Create: `tests/playwright/utils/paypal.js`
- Modify: `src/Service/Express/ExpressOrderBuilder.php`
- Create: `tests/Unit/Service/AddressNormalizerTest.php`

- [ ] port the WooCommerce `utils/paypal.js` — it already polls both the popup and the in-page `about:blank` overlay and accepts only a frame that reached paypal.com (constraint 5)
- [ ] handle the PayPal partial address — name, email, country, **no street** (constraint 3)
- [ ] decide and document the fallback: placeholder street, or prompt the shopper for the missing fields
- [ ] make sure the partial address does not fail PrestaShop's strict `Address` validation
- [ ] keep virtual and digital-product orders working, where no shipping address is needed
- [ ] write unit tests: full address, partial PayPal address, missing country, virtual cart
- [ ] write a Playwright spec for PayPal express with a no-address sandbox account
- [ ] run tests — must pass before Task 17

### Task 17: Translations

**Files:**
- Modify: `translations/*.php`
- Modify: `monei.php`, `views/js/front/express.js`, templates

- [ ] wrap every new admin label, express button label, and error string in `$this->l()` with the module's `<{monei}prestashop>` key convention
- [ ] confirm client-side strings reach `express.js` via `Media::addJsDef` or data attributes, never a raw `$this->l()` in a static file
- [ ] regenerate or hand-add keys for the ~25 existing locale files
- [ ] verify the module's translation page in the back office shows the new strings
- [ ] write a Playwright spec asserting a non-English locale renders translated express strings
- [ ] run tests — must pass before Task 18

### Task 18: CI and release packaging

**Files:**
- Create: `.github/workflows/test.yml`
- Modify: `.github/workflows/release.yml`

- [ ] add a **blocking** PR workflow: PHP-CS-Fixer dry-run, PHPUnit, ESLint. Fast and deterministic.
- [ ] add a **non-blocking** nightly workflow standing up Flashlight and running Playwright. Tag the PayPal specs and exclude them from any blocking job — constraint 5 describes a cross-origin iframe with a popup-vs-overlay race, and putting that in required CI buys a permanently flaky red pipeline.
- [ ] store MONEI test credentials as GitHub secrets, never in a workflow file (constraint 7)
- [ ] pin action versions to SHAs
- [ ] **fix the release ZIP exclusions** in `release.yml:26`. Today it is `-x '*.git*' -x '/build/*' -x 'monei/output.log'`; the `/build/*` pattern never matches because paths inside the ZIP are `monei/build/*`. Without a fix, `package.json`, `eslint.config.js`, `.prettierrc`, `node_modules/`, `tests/`, and `.env` all ship to merchants and to the PrestaShop Addons validator — the same class of bug commit `4df9fef` just fixed.
- [ ] confirm the workflows actually go green — **parse the output, do not trust the exit code** (constraint 6)
- [ ] run tests — must pass before Task 19

### Task 19: Verify acceptance criteria

- [ ] express buttons appear on product, cart, and checkout when enabled, and nowhere when disabled
- [ ] no duplicate wallet buttons at checkout
- [ ] Apple Pay, Google Pay, and PayPal each complete an express order end to end
- [ ] a failed express order shows the shopper an error **on every surface** and restores their original cart
- [ ] guest express checkout works from a logged-out product page
- [ ] CardGroup split fields are the default; the `single` setting reverts to CardInput
- [ ] a CLI or webservice status change triggers auto-capture, and the target status survives it
- [ ] MB WAY and Multibanco behave per the Task 7 decision, and the merchant is told
- [ ] a PayPal no-address account completes an order
- [ ] **upgrading a real 2.0.18 install** registers the new hooks and seeds the new defaults
- [ ] the built release ZIP contains no `tests/`, `node_modules/`, `package.json`, or dotfiles
- [ ] **rollback checks**: turning `MONEI_EXPRESS_ENABLED` off removes every button and leaves normal checkout intact; setting `MONEI_CARD_LAYOUT=single` after a `split` payment does not break saved cards
- [ ] verify edge cases: virtual cart, variable product, empty cart
- [ ] run `composer test`, the full e2e suite, `php-cs-fixer --dry-run`, and ESLint — all clean

### Task 20: [Final] Documentation, changelog, release

**Files:**
- Modify: `CHANGELOG.md`
- Modify: `CLAUDE.md`
- Modify: `README.md`

- [ ] write the changelog entry, **explicitly flagging the CardGroup default as a visual change on upgrade** and naming the one setting that reverts it
- [ ] document the new express and capture settings in `README.md`
- [ ] **fix the stale schema list in `CLAUDE.md`** — it documents a `monei2_admin_order_message` table that does not exist in `src/Entity/` or `sql/`
- [ ] update `CLAUDE.md`: the express service layout, the root `package.json` and lint commands, the Playwright suite, the fact that JS moved out of `displayPaymentByBinaries.tpl` into `views/js/front/payment.js`, and that `views/js/` is still served directly with no bundler
- [ ] move this plan to `docs/plans/completed/`

## Post-Completion

*External action required — no checkboxes.*

**Manual verification:**
- Apple Pay on a real iOS device with a real card. The simulator does not prove it.
- Google Pay on a real Android device.
- At least one non-classic PrestaShop theme — hook placement is theme-dependent and Task 14 only verifies classic.
- Confirm capture actually settles in the MONEI dashboard, not merely that the API returned 200.

**External system updates:**
- Docs PR against `MONEI/docs` for `https://docs.monei.com/e-commerce/prestashop`. Strict, non-obvious build gates: escaped heading anchors, five canonical admonition types, enforced EN+ES parity, a changelog bullet required in **both** locales, `onBrokenAnchors: throw`. Read its `CLAUDE.md` first. Run `pnpm qa:docs`, `pnpm qa:es-sync --staged`, and a full `pnpm build` before pushing. A fresh clone needs `static/openapi.json` fetched and `pnpm genapi:rest` run.
- Docs screenshots at 1440×805 @2x to match existing `configure-*` images; blank account IDs in the DOM before capturing.
- Release via `cd build && yarn release`.
- Trello: check for an existing card before creating one. WooCommerce work is card 5570 at `https://trello.com/c/bAzf5MZr`.

**Known environment risk:**
- Network on this machine was dropping intermittently during the WooCommerce round, causing spurious e2e failures (`ERR_INTERNET_DISCONNECTED`, `ERR_NETWORK_CHANGED`) and a `no route to host` on a `gh` call that had in fact succeeded server-side. Before debugging a red suite, check connectivity and check actual GitHub state rather than trusting a local error.
