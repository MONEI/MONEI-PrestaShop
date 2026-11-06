# MONEI Customers API for PrestaShop

Contract: [20261105-monei-customers-api-contract.md](20261105-monei-customers-api-contract.md) (v8.2), copied without changes. All mapping reads, and the v8 re-check, skip the Db query cache (v8.1).
This plan maps each contract item to PrestaShop code. It does not change the contract.
All sandbox checks are done (contract v7, "Sandbox results"). Their results are applied below. Nothing in this plan waits on the contract any more.

Three checks do not apply to PrestaShop:
- **e:** this module makes no recurring charges.
- **g:** this module has no add-card flow.
- **c, one-time-token part:** PrestaShop creates the payment on the server before monei.js confirms it, so the first request carries no one-time token.

Saved-card ownership was checked (the WooCommerce bug): `createMoneiPayment` loads the card with `id_customer` = the cart's customer (`MoneiService.php:683`), and `redirect.php` checks the cart + customer hash. No fix is needed.

## Context

- **Today's saved cards:** a saved card exists only in `monei2_customer_card`. `MoneiService::saveMoneiToken` writes it. `controllers/front/customerCards.php` deletes only the local row, so the token stays valid at MONEI. A guest can also save a card: `PaymentOptionService.php:184` shows the checkbox to any loaded customer.
- **One payment path:** all create-payment calls go through `MoneiService::createMoneiPayment` (`src/Service/Monei/MoneiService.php`). Its callers are `controllers/front/redirect.php`, `controllers/front/createPayment.php` (components, Apple Pay, Google Pay, Bizum) and `controllers/front/express.php`. Its `try` and the callers' `catch` blocks catch only `\Exception`.
- **Shop scope:** MONEI settings are per shop in multistore. `getMoneiClient()` and the payment metadata `shop_id` read the **context shop**. The contract scope parameter is `int $idShop`, and its value always comes from `Context::getContext()->shop->id`.
- **Unit harness:** it does not boot PrestaShop (`tests/Unit/HarnessTest.php`), and `vendor/` has no Doctrine ORM. `MoneiService.php` and `monei.php` call `exit` when `_PS_VERSION_` is undefined, so autoloading either one ends PHPUnit with exit code 0. Testable code must be pure and guard-free, like `CaptureTrigger`. `ArchitectureTest` enforces this rule (task 8).
- **Doctrine:** it is unusable in cron, webservice and CLI contexts (see the comment in `hookActionOrderStatusPostUpdate`). A duplicate-key flush also closes the shared EntityManager. So all new database access uses `Db::getInstance()`.
- **psgdpr payloads** (checked in the source). PS 9.1.4 still bundles psgdpr **1.4.3**, so the 1.4 shape is the common case:

  | Erasure | psgdpr 1.4.4 | psgdpr 2.x |
  |---|---|---|
  | By ID | `(array) $customer`, so `['id' => <int>, 'email' => …, …]` | `['<id>']` |
  | By email | `['email' => …]` | `[['email' => …]]` |
  | By phone | – | `[['phone' => …]]` |

  For an erasure by ID, 1.4 also deletes the `Customer`. Email and phone erasures delete no `Customer`.
- **PS 8 soft delete:** a back-office delete with "do not allow registration" (`DeleteCustomerHandler`, `BulkDeleteCustomerHandler`) sets `deleted = true` and calls `update()`. Core does not end the customer's open session.
- **Hook registration:** `Hook::registerHook` (PS 8.2.3 `classes/Hook.php:596-627`; PS 9.1.4 `:644-690`) deletes the module's `module_shop` row for every shop in the list that is not in `Shop::getShops(true, null, true)`. That list holds only active shops **and** is filtered by the context employee's shop permissions. This happens **even with an explicit shop list**. So registering turns MONEI off on inactive shops, and on any shop the employee who runs the upgrade cannot see. `install()` and `upgrade/upgrade-2.1.0.php` have the same problem. They are fixed in a separate PR (user decision), not here (Rule 3).
- **SDK 2.13.0:** the contract's "SDK rules" apply. `$client->customers` is a `CustomersApi` with `create`, `delete($id)` and `deletePaymentMethod($id, $tokenId)`. `ApiException::getCode()` is the HTTP status.
- **Version bump:** a commit that adds an upgrade script also bumps `Monei::VERSION` and `$this->version` (see 4b54ee7).
- **Before you start:** local `vendor/` has SDK 2.8.0, but `composer.json` requires `^2.13`. Run `composer install` first.
- **Dev stack:** `/Users/dmitriy/Work/prestashop` (`monei-prestashop-dev-env`) mounts this checkout. It runs PrestaShop 9.1.4 with PHP 8.5 by default, and `PS_IMAGE_TAG=8.2.3-nginx` gives 8.x. All PS facts in this plan were checked on both 8.2.3 and 9.1.4. Run Playwright on both versions.

## Contract → PrestaShop

| Contract item | PrestaShop |
|---|---|
| Service `CustomerService` | `src/Service/Monei/CustomerService.php`, no guard. The 4 contract methods: <ul><li>`getOrCreateCustomerId(int $idShop, int $idCustomer, string $email, string $name, string $phone): ?string`. An empty string means the field is left out.</li><li>`forgetCustomerId(int $idShop, int $idCustomer, string $staleCustomerId): void`.</li><li>`deletePaymentMethod(int $idShop, int $idCustomer, string $paymentToken): void`.</li><li>`deleteCustomer(int $idCustomer): bool`. It returns true when no row is left. The return value is PrestaShop-only and does not change the contract behaviour: the GDPR hook needs it to report a failed MONEI delete to psgdpr.</li></ul> Every method catches `\Throwable` and never throws. PHP 7.4: `callable` types go on constructor parameters only. Properties are untyped, with docblocks. |
| Service dependencies | <ul><li>`CustomerMappingStoreInterface $store`</li><li>`callable $accountForShop`: `int $idShop` → raw `{accountId, mode, apiKey}`</li><li>`callable $configuredAccounts`: → `mergeAccounts` output, read only by `deleteCustomer`</li><li>`callable $customersApi`: `string $apiKey` → `CustomersApi`</li><li>`callable $log`: `fn(string $message, int $severity)`</li></ul> |
| SDK rules: clients | Pure `src/Service/Monei/MoneiClientFactory.php`, static `create(string $apiKey, string $userAgent): MoneiClient`. It always passes `new \Monei\Configuration()`. Callers pass `MoneiService::getUserAgent()`. `getMoneiClient()` and `testApiConnection` (`monei.php`) both use it. |
| SDK rules: fields | A `CreateCustomerRequest` setter is called only for a non-empty value. The stale retry removes the customer ID with `offsetSet('customer_id', null)`. |
| `deleteCustomer` matching | Pure static `CustomerService::mergeAccounts(array $shopSettings): array`, keyed by (`accountId`, `mode`). <ul><li>It keeps an entry only when that mode's account ID and key are both non-empty.</li><li>A shared (`accountId`, `mode`) keeps every distinct key, active shops first and inactive shops after, whatever the input order.</li><li>A row matches only the entry with the **row's own mode** and the same account ID. `delete` uses the entry's first key.</li></ul> |
| Service wiring | `Monei::buildCustomerService()` in `monei.php`. It uses no Doctrine and does not use `monei.service.monei`. <ul><li>Settings: `Configuration::get($key, null, Shop::getGroupFromShop($idShop), $idShop)`.</li><li>`$configuredAccounts`: both modes of every shop, with each shop's `active` flag, from a direct `SELECT id_shop, active FROM <prefix>shop`. Do not use `Shop::getShops()`. Its cache filters by the context employee's shop permissions (`Shop::cacheShops`, PS 8.2.3 `classes/shop/Shop.php:695-700`). An employee limited to shop A who deletes a shared customer would then leave out shop B's keys, and B's MONEI customer would stay.</li><li>`config/common.yml`: `monei.service.customer` with `factory: ['@monei.module', 'buildCustomerService']`. `monei.service.monei` gets it as a new **optional** constructor argument, `?CustomerService $customerService = null`, where `null` means no linking. A back-office container cached before the file swap still builds with 5 arguments. With `null`, `createMoneiPayment` skips both `PaymentCustomerLink` helpers and calls `payments->create` directly. Passing `null` to a helper would throw a `TypeError`, which the `catch (\Exception)` there does not catch.</li><li>Hooks call `$this->buildCustomerService()`.</li></ul> |
| Mapping, PS table | `monei2_customer` (`id_monei2_customer` PK, `id_customer` INT, `account_id` VARCHAR(64), `mode` VARCHAR(4), `monei_customer_id` VARCHAR(64), `date_add` DATETIME), UNIQUE (`id_customer`, `account_id`, `mode`). It is defined only in `sql/install.php`, where every statement is `CREATE TABLE IF NOT EXISTS`. The upgrade runs that file. `sql/uninstall.php` does **not** drop it (user decision). A dropped table would leave every MONEI customer with PII and no mapping, and a reinstall would create duplicates. A reinstall reuses the rows. |
| Mapping access | `src/Repository/CustomerMappingStoreInterface.php`, no guard: <ul><li>`findMapping(int $idCustomer, string $accountId, string $mode): ?string`</li><li>`findAllForCustomer(int $idCustomer): array`</li><li>`insertMapping(...): bool` (`INSERT IGNORE`; `true` also when a duplicate is ignored)</li><li>`deleteMapping(int $idCustomer, string $accountId, string $mode): void`</li><li>`deleteMappingIfHolds(int $idCustomer, string $accountId, string $mode, string $moneiCustomerId): void` (v6 `forgetCustomerId`)</li></ul> The reads use `Db::executeS($sql, true, false)`, which **bypasses the PrestaShop query cache** (v6.1): with `_PS_CACHE_ENABLED_`, a cached empty first read would turn a lost race into a "failed save". They **throw** when the result is `false`, because a DB error is not "no row". The production class is `src/Repository/CustomerMappingStore.php` on `Db::getInstance()`. Tests use an in-memory fake. |
| `getOrCreateCustomerId` flow | <ol><li>The account ID is empty: debug log, return `null`.</li><li>`findMapping` throws (for example, the table is missing): error log, return `null`, no `create`.</li><li>A mapping exists: return it.</li><li>Otherwise `create`, then `insertMapping`, then `findMapping` again: <ul><li>The new ID is stored: a fresh `platformCustomerExists` read (`ps_customer` with `deleted = 0`, query cache off). Customer gone (v8): delete the new customer (error log on failure), `deleteMappingIfHolds`, return `null`. Otherwise return it. No marker is needed: PS 9.1.4 `ObjectModel` fires `actionObjectCustomerDeleteAfter` after the row delete and `UpdateAfter` after `deleted` is written, so a delete whose hook read no mapping is always visible to this read.</li><li>Another ID is stored (concurrent create): delete the new customer (best effort; on failure, **error** log with its ID). Keep the winning row and return its ID.</li><li>The insert returns `false` or throws, or the re-read is null (save failed): delete the new customer (best effort; on failure, **error** log with its ID), **error** log, return `null`.</li><li>The re-read throws after a successful insert: return `null`, with **no** delete, because the row may hold the new ID. The error log holds the new MONEI customer ID, the account ID and the mode, because `INSERT IGNORE` also returns `true` on a lost race, and then this log line is the only record of the customer.</li></ul></li></ol> |
| Create request | It is built from the `PaymentCustomer` that `createMoneiPayment` already makes with `getCustomerData`. The phone comes from the cart invoice address, because a PS `Customer` has no phone. The email has colons removed. `metadata` = `{"platform":"prestashop","platformCustomerId":"<id as string>"}`. Any phone format is sent as it is (check h: local and E.164 are both accepted). |
| Which payments | Only `createMoneiPayment`, so all three callers are covered, through pure `src/Service/Monei/PaymentCustomerLink.php` (no guard): <ul><li>`link(CustomerService $s, CreatePaymentRequest $r, int $idShop, int $idCustomer, bool $isGuest, bool $isDeleted, PaymentCustomer $data): void` <ul><li>A guest or a soft-deleted customer gets no call.</li><li>Otherwise it calls `getOrCreateCustomerId` with `(string)` casts (`getPhone()` can be `null`) and sets `customerId` when the result is not null.</li><li>The whole body is in `try/catch (\Throwable)`.</li></ul></li><li>`create(...)` handles the stale retry.</li></ul> Both run inside the existing `try`. The inline `customer` block stays. Every payment method accepts `customerId` (check i), so `link()` does not look at the method. |
| Flows PrestaShop does not have | Zero-amount add card, subscription first payment, automatic renewals. Not applicable. |
| Guests | ⚠️ A PrestaShop guest **has** an `id_customer` (`is_guest = 1`). Express also creates guest rows (`ExpressOrderBuilder`). `link()` treats `is_guest` as "no platformCustomerId". |
| Saved card paid with an old token (check d) | The `$cardTokenId` branch gets `customerId` like every other payment: MONEI accepts it. The old token is not attached to the customer, so a later `deletePaymentMethod` for it gets 404 (debug log). |
| Stale customer → one retry | `PaymentCustomerLink::create(CustomerService $s, CreatePaymentRequest $r, int $idShop, int $idCustomer, callable $create)`. <ul><li>It retries only if the request held `customer_id` before the first call.</li><li>On the stale error: `forgetCustomerId($idShop, $idCustomer, $staleId)`, then `$r->offsetSet('customer_id', null)`, then one more `$create`. A failure of that retry is rethrown.</li><li>Any other error is rethrown unchanged.</li></ul> **Stale error** (check c): MONEI rejects a deleted customer **at create** with `ApiException` code 404 and a response body whose `message` is exactly `Customer not found`. The match needs both, because a 404 alone also covers other errors. The v7 open point (retry after a one-time monei.js token) does not apply here: `createMoneiPayment` never sends a one-time token. Its only token is a saved card's permanent token. |
| `deleteCustomer`, all rows | `findAllForCustomer` throws: error log, stop. For each row, in its own `try/catch (\Throwable)`, find the entry with the row's mode and account ID: <ul><li>No match: **error** log, keep the row, go on.</li><li>Otherwise `delete`. Success or 404: remove the row. 409 or another error: **error** log, keep the row, go on.</li></ul> A customer delete also revokes its tokens (check f), so no method is deleted first. **Step 0** (check e, subscriptions): n/a. This module has no subscriptions and makes no `sequence` or `recurring` charges, so no platform subscription can charge a MONEI sequence. |
| `deletePaymentMethod` | Current account + mode only. No mapping: no API call. 404: debug log. 409: warning log. Other error: error log. It **never** removes the mapping, and it never throws. |
| Logs | Prefix `[MONEI Customers]`. Debug = severity 1, warning = 2, error = 3. With the default `MONEI_LOG_LEVEL` of 3, only errors are stored. So every case that can leave PII at MONEI without a mapping logs at error. Every delete log line holds the MONEI customer ID, the account ID and the mode. |
| Trigger: card deleted | `customerCards.php` `displayAjaxDeleteCustomerCard`, in this order: <ol><li>`$token = Tools::getValue('token');` then refuse unless `is_string($token) && hash_equals(Tools::getToken(false), $token)`. Do **not** reuse `FrontController::isTokenValid()`: it returns true when `PS_TOKEN_ENABLE` is off, which is the default. Today the delete has no CSRF check, and from now on it also removes the token at MONEI. `views/js/front/customerCards.js` sends `prestashop.static_token`.</li><li>Keep the existing ownership filter (`id_customer`). For another customer's card ID, the response stays `success: true`, the row stays, and MONEI is not called.</li><li>`deletePaymentMethod((int) $this->context->shop->id, id_customer, $card->getTokenized())`.</li><li>Remove the local row.</li></ol> The stored token is the payment-method ID (check a), so no lookup is needed. |
| Trigger: customer deleted | `actionObjectCustomerDeleteAfter` (`$params['object']`). Also `actionObjectCustomerUpdateAfter` when `$params['object']->deleted` is truthy (soft delete). Each runs two separate `try/catch (\Throwable)` steps: <ol><li>`deleteCustomer`</li><li>`Db::getInstance()->delete('monei2_customer_card', 'id_customer=' . (int) $id)`</li></ol> |
| Trigger: GDPR | `hookActionDeleteGDPRCustomer` uses the pure `src/Service/Monei/GdprPayload.php`. Its static `parse($params): array` returns `['id' => int]`, `['email' => string]` or `[]`, and it checks the ID first. <ul><li>ID: `$params['id']` or a scalar `$params[0]`. Accepted when it is a positive int, or `is_scalar` + `ctype_digit((string) $v)` + `(int) $v > 0`.</li><li>Email: `$params['email']` (1.4) or `$params[0]['email']` (2.x).</li></ul> With an ID, it runs the same two steps. With an email, it runs both steps for **every** ID that `Customer::getCustomersByEmail` returns, guests included, because a guest can save a card. `deleteCustomer` is a no-op for a guest. If the lookup returns `false` (DB error), that is a failure. The hook returns `json_encode(true)` only when every `deleteCustomer` returned true and every card delete succeeded. Otherwise it returns an error string, as today (`monei.php:3001`). |

## Tasks

1. `composer install`, then confirm that `vendor/monei/monei-php-sdk` is 2.13.0 with `lib/Api/CustomersApi.php`.
2. Schema: `monei2_customer` in `sql/install.php` only, not in `sql/uninstall.php`. Add `CustomerMappingStoreInterface` and the `Db`-backed `CustomerMappingStore`.
3. `MoneiClientFactory`. `getMoneiClient()` and `testApiConnection` use it.
4. `CustomerService` (with `mergeAccounts`), `Monei::buildCustomerService()`, `config/common.yml` (new service, optional `monei.service.monei` argument).
5. `PaymentCustomerLink` (`link` + `create`), wired into `createMoneiPayment` inside its `try`. 
6. Triggers:
   - card delete with the token check (PHP and `customerCards.js`)
   - `actionObjectCustomerDeleteAfter` and `actionObjectCustomerUpdateAfter`, registered in `install()`
   - the GDPR hook with `GdprPayload` and the email lookup

7. `upgrade/upgrade-2.2.0.php`:
   - Run the statements from `sql/install.php`, and fail the upgrade when the include returns `false`.
   - Before registering, read **all** of the module's `module_shop` rows.
   - Register the two hooks on every shop, from `Shop::getCompleteListOfShopsID()`. Do not use `getShops()`, which filters by the employee who runs the upgrade.
   - Restore any of those `module_shop` rows that `registerHook` deleted (`INSERT IGNORE`). This keeps MONEI enabled on inactive shops and on shops the upgrading employee cannot see.
   - Verify every shop in **that same list** with `Hook::isModuleRegisteredOnHook`. The return value of `registerHook` does not prove that the hook was registered.
   - A Playwright test cannot set up a second, inactive shop in the single-shop dev stack. So check this once by hand on a multistore stack: after the upgrade, the inactive shop still has its `module_shop` row and has both hooks.

   In the same commit, bump `Monei::VERSION` and `$this->version` to 2.2.0.
8. Unit tests use a mocked `CustomersApi` and the in-memory store. Files: `tests/Unit/Service/CustomerServiceTest.php`, `PaymentCustomerLinkTest.php`, `MoneiClientFactoryTest.php`, `GdprPayloadTest.php` and `tests/Unit/ArchitectureTest.php`. Test and live always use the **same** account ID.

   Contract tests:
   - **1:** `link()` for a guest: no `create`, and `offsetExists('customer_id')` is false.
   - **2:** an existing mapping is reused, with no `create`.
   - **3:** `create` throws an `\Error`. `link()` returns normally, no `customer_id` is set, and no mapping is stored.
   - **4:** a test-mode mapping is never returned in live mode.
   - **5:** delete 404 at debug level. `deleteCustomer` removes the row, and `deletePaymentMethod` keeps the mapping. `deleteCustomer` with a 500 keeps the row and logs an error with the customer ID, account ID and mode.
   - **6** (stale error = `ApiException` 404 + body message `Customer not found`):
     - Both `$create` calls fail as stale: exactly 2 calls. Serialize the request inside the callable (`ObjectSerializer::sanitizeForSerialization`): the first call has `customerId`, the second does not. The retry failure is rethrown, and the mapping is gone.
     - A mapping that already holds a newer ID is kept.
     - An unrelated error with the **same** HTTP status is rethrown, with no retry.
     - A request without `customer_id` is never retried.
   - **7:** a test row and a live row get two deletes, each with the key of the row's own mode, and both rows are removed. A row that matches no entry is kept. When the first row throws an `\Error`, the next row is still deleted.
   - **8:** `link()` with a `PaymentCustomer` whose phone is `null`. The create request has exactly `email`, `name` and `metadata` `{"platform":"prestashop","platformCustomerId":"42"}`, with no `phone` key, and the buyer is linked.
   - **9:** get the main client, then the other mode's client, then the main client again. Each sends its own key.
   - **10:** the store holds another ID after the insert. That ID is returned, the new customer is deleted, and the winning row stays. If the insert returns `false`: the result is `null`, and the new customer is deleted.
   - **11:** `deletePaymentMethod` with no mapping makes no API call.
   - **12:** an empty account ID gives no `create`, no `customerId` and no stored mapping.
   - **13:** n/a. The module has no sequence-based subscriptions (step 0 does not apply).

   Other tests:
   - The insert throws: the new customer is deleted, and the result is `null`.
   - The insert returns `true` and the re-read is null: the new customer is deleted, and the result is `null`.
   - The re-read throws after a successful insert: the result is `null`, and nothing is deleted.
   - `findMapping` throws: no `create`, and the result is `null`.
   - `deleteCustomer` with a 409 keeps the row and logs an error.
   - `link()` for a soft-deleted customer makes no `create` call.
   - `deletePaymentMethod` with test and live rows calls `(liveCustomerId, token)` with the live key. When the API throws an `\Error`, it does not throw, because `customerCards.php` catches only `Exception`.
   - `deleteCustomer` with the `mergeAccounts` output below deletes with T1, not T0.
   - `mergeAccounts`. Input order:
     1. inactive shop with account A, test key T0
     2. active shop with account A in both modes, keys T1 and L1
     3. active shop with account A, empty test key and live key L2
     4. shop with an empty account ID

     Expected: two entries. Test is `[T1, T0]`, live is `[L1, L2]`. The fourth shop adds nothing.
   - `GdprPayload::parse`:
     - `['id' => 42, 'email' => 'a@b.c']` → id 42
     - `['7']` → id 7
     - `['1e3']`, `['0']` and `[0]` → `[]`
     - `['email' => 'a@b.c']` → email
     - `[['email' => 'a@b.c']]` → email, with no PHP warning
     - `[['phone' => '600']]` → `[]`
   - `ArchitectureTest`:
     - No match for `new\s+\\?(Monei\\)?MoneiClient\s*\(` in `src/`, `controllers/`, `upgrade/` or `monei.php`, except in `MoneiClientFactory`.
     - The pure classes (`CustomerService`, `PaymentCustomerLink`, `GdprPayload`, `MoneiClientFactory`, `CustomerMappingStoreInterface`) must not reference any guarded or global PrestaShop class.
       - **Comments are skipped.** The test reads the code with `token_get_all` and leaves out comment tokens, so a word such as "Context" in a docblock does not match.
       - **`Monei`:** `(?<![\w\\])\\?Monei\b(?!\\)`. It matches `use Monei;`, `\Monei::` and `Monei::`. It does not match `namespace PsMonei\Service\Monei;`, `use Monei\Model\…` or `\Monei\Configuration`.
       - **PrestaShop globals:** `(?<![\w\\])\\?(Configuration|Db|Context|Shop)\b(?!\\)`. It matches `\Configuration::`, `Db::` and `use Db;`. It does not match `\Monei\Configuration`.
       - **Guarded classes:** the test collects every class whose file in `src/` has the `_PS_VERSION_` guard. It matches each one by short name with word boundaries (`\bMoneiService\b`), because a class in the same namespace needs no `use` line.
       - **Pattern check:** both patterns were run against these 12 forms before they went into the plan, and all passed.
     - `CustomerMappingStore` calls `executeS` only with a literal `false` as the third argument (v6.1 cache bypass). With the cache off, e2e cannot see this.
9. Playwright. Every MONEI call in `psEval` loads the module `vendor/autoload.php` and builds the client directly, because `service.monei` needs Doctrine. Every hook test first sets the CLI context shop, as `setOrderState` in `utils/ps-cli.js` does. Customers in delete tests are real MONEI customers.
   - **Guest:** in `baseline-card.spec.js`, read `id_customer` from the order and assert that it has no `ps_monei2_customer` row.
   - **Logged-in:** a new logged-in checkout helper. Turn `MONEI_TOKENIZE` on and restore it afterwards. Pay with "save card". The stored row is (`MONEI_TEST_ACCOUNT_ID`, `test`), and `payments->get(id_payment)->getCustomerId()` equals its `monei_customer_id`.
   - **Card delete security:** `page.request` POSTs against the logged-in customer's saved card. Cases: missing token, wrong token, `token[]`, and another customer's `customerCardId` with the attacker's own valid token. After each one, the local row stays, and the owner's `listPaymentMethods` count at MONEI is unchanged.
   - **Card delete UI:** delete through the modal; the local row is gone. `listPaymentMethods` no longer holds the token.
   - **Real store:**
     - Insert a test row and a live row.
     - `findMapping` for live returns the live ID.
     - A second test insert with another ID returns `true` and keeps the first ID.
     - `deleteMappingIfHolds` with a wrong ID keeps the row. With the matching ID, it removes the row.
     - `deleteMapping` for test keeps the live row.
   - **Customer delete:** seed a mapping and a card row for a real customer, and check that `customers->get` succeeds. Run `delete()`: both rows are gone, and `customers->get` returns 404.
   - **Soft delete:** the same setup. First `update()` with `deleted = false`: the rows stay. Then `deleted = true` + `update()`: the rows are gone.
   - **GDPR by ID:** the same setup, then `Hook::exec('actionDeleteGDPRCustomer', ['<id>'], null, true)`. The rows are gone, and the module result is `json_encode(true)`. The MONEI 404 check stays only in the customer-delete test, because it covers the same `deleteCustomer` rule.
   - **GDPR by email:** a registered customer and a guest with the same email, each with a card row, and a mapping for the registered one. Run `Hook::exec` with `[['email' => …]]`. All rows are gone.
   - **Upgrade:** in `upgrade.spec.js`, keep the rewind to 2.0.18, and also drop `ps_monei2_customer` (`mysql()`) and detach both hooks. While the table is dropped, before the upgrade, `findMapping` on the real store throws, so it does not return null. After the upgrade: the table exists, both hooks are registered, and the version is 2.2.0. Update the describe name.
10. `CLAUDE.md` updates:
    - Add `monei2_customer` to "Database Schema".
    - Add a short "Customers API" section:
      - the contract file;
      - the pure-class rule and `ArchitectureTest`;
      - all customer DB access goes through `Db` with no query cache.
11. `composer test`, `npm run lint`, PHP-CS-Fixer in a PHP 8.3 container.
12. Release: `cd build && yarn release` as 2.2.0.

## Grill resolutions

- **Feature toggle:** none. The contract has none, so linking is always on when an account ID is set.
- **`MONEI_TOKENIZE`:** linking does not depend on it. Every payment of a known, non-guest, non-deleted customer carries `customerId`, with or without "save card" (contract: "every create-payment call where the platform customer is known").
- **Bizum and SEPA saved methods:** out of scope. The local `monei2_customer_card` table stays the source of truth (contract), and it stores cards only.
- **Translations:** none. No shopper-facing text is added. A refused card delete returns the same response as today.
- **Terms:** the plan uses the contract terms. "Mapping" means a `monei2_customer` row, `platformCustomerId` is `id_customer`, and `paymentToken` is `monei2_customer_card.tokenized`.

## Known limits (accepted)

- **Customer deleted in the MONEI dashboard:** that revokes its tokens (check f). A local card saved under it then fails with 400 `Invalid payment method`, also on the stale retry. The local row stays until the buyer deletes it.

- **Card deleted in another shop:** a card saved in shop A on another account and deleted from shop B gets a 404, which is ignored. The token stays at A, because `deletePaymentMethod` uses the current account (contract).
- **psgdpr erasure by phone:** a PS `Customer` has no phone field, so no customer can be found.
- **Back-office bulk delete:** it makes one MONEI call per mapping row inside the request.
- **No timeout on customer calls:** they use the SDK default Guzzle client with no timeout, the same as payment create today.
- **Revoked or stale first key:** a revoked first key gets 401, so the row is kept and an error is logged. A stale key from another account under the same account ID gets 404, so the row is removed. Putting active-shop keys first makes this less likely.
- **Module disabled in a shop:** in multistore, if the module is disabled in the admin's context shop, the delete hooks do not fire.
- **Delete during a payment:** a payment that is in progress while its customer is deleted can still create a MONEI customer or save a card after the delete. A soft-deleted customer's live session can still save a card (`saveMoneiToken`, existing behaviour).
- **Reused customer ID:** a row kept after a failed delete stays under the deleted `id_customer`. A new customer that gets the same ID after a MySQL 5.7 AUTO_INCREMENT reset gets that mapping.
- **Uninstall keeps `monei2_customer`:** the table stays in the database after an uninstall (user decision).
