# MONEI Customers API: cross-platform contract (v8.2)

One behavior for the WooCommerce, Magento 2 and PrestaShop plugins. Each plugin
plan copies this file without changes. A change to the contract goes through
the WooCommerce thread, gets a new version number, and is sent to all three.

Docs: https://docs.monei.com/apis/rest/customers/
SDK: `monei/monei-php-sdk` 2.13.0, `$client->customers`.

## Changelog

- v8.2: no rule change. New "Known limits" section: multi-account stores (Magento multi-store, PrestaShop multishop, WordPress multisite with a MONEI account per store).
- v8.1: the delete hook reads the mappings, and the create re-check reads the delete marker, from the database, not from a cache loaded earlier in the request (WooCommerce review: `wp_delete_user` loads the user meta cache before `delete_user`; `get_option` can answer from a cached absence).
- v8: a mapping saved for a platform customer that is deleted meanwhile is cleaned up (Magento external review). New Errors row "Platform customer deleted during create", Mapping rule and test 14.
- v7: sandbox results (WooCommerce and Magento threads, 2026-11-06). New rule: `deleteCustomer` skips a customer whose active platform subscription charges a MONEI sequence (check e). The stale-customer match is HTTP 404 and message `Customer not found` (check c). The add-card payment carries `customerId` (check g). No phone filter (check h). Every method accepts `customerId` (check i). New test 13.
- v6.1: `deleteCustomer` step 1 logs a row with no match at error level (it said warning, against the Errors table). The re-read after a lost mapping race must bypass any cache.
- v6:
  - Log levels: a case that can leave a MONEI customer with PII and no mapping logs at **error** (the plugins drop warnings by default).
  - `forgetCustomerId` removes the mapping only while it still holds the stale ID.
  - Concurrent create: an empty re-read is a save failure, not a lost race.
  - `deletePaymentMethod` never removes the mapping.
  - "Never on renewals" means automatic renewal charges only.
  - Tests 3, 5, 6, 7, 9 and 10 are tightened.
  - Sandbox check c records create versus confirm; new check i (every payment method accepts `customerId`).
  - Sandbox results move to v7.
- v5:
  - A field is removed from a model with `offsetSet(…, null)`, not `offsetUnset`.
  - `deleteCustomer` matches each row on its own mode, never on the account ID alone, and needs a non-empty key.
  - Fail-open covers every `\Throwable`.
  - Delete error logs hold the MONEI customer ID, account ID and mode.
  - Tests 4, 5, 6 and 7 are tightened; new tests 11 and 12.
  - Sandbox check b is dropped; new checks f, g and h; checks c and the method for all checks are made precise.
  - Sandbox results move to v6.
- v4: concurrent create. When saving the mapping finds a stored ID, the new MONEI customer is deleted and the stored ID wins (no unmapped customer with PII). New test 10.
- v3: SDK rules (empty values are left out, a field is removed and never set to `null`, each extra client gets its own `Configuration`); new sandbox check e (does a delete break a renewal sequence).
- v2: a scope parameter is allowed; `deleteCustomer` deletes every mapping; phone fallback to the billing address; new sandbox check d; platform-specific flows apply only where the platform has them.
- v1: first version.

## Scope

In scope: link payments of logged-in buyers to a MONEI customer, and delete at
MONEI when a saved card or a buyer is deleted on the platform.

Out of scope: list saved methods from MONEI, `useDefaultPaymentMethod`,
attaching tokens saved before this change, MONEI Subscriptions, updating the
MONEI customer when the buyer edits their profile, a retry queue for failed
deletes.

The platform token store stays the source of truth (WC tokens, Magento Vault,
`monei2_customer_card`).

## SDK rules (monei-php-sdk 2.13.0)

- The model setters throw `InvalidArgumentException` on `null` (`CreateCustomerRequest::setEmail/setName/setPhone/setMetadata`, `CreatePaymentRequest::setCustomerId`). Never pass `null` to a setter.
- To leave a field out: do not call its setter, or leave the array key out.
- To remove a field that is already set:
  - array payload: `unset( $payload['customerId'] )`;
  - model: `$request->offsetSet( 'customer_id', null )`. Do not use `offsetUnset`: the getter then reads a missing array key, and the serializer calls every getter, which gives a PHP warning that can corrupt an AJAX response. The serializer leaves out `null` values of non-nullable fields, and `offsetExists` returns false.
- `new MoneiClient( $key )` with no config writes the key into the shared static `Configuration::getDefaultConfiguration()`, and every client that uses it then sends that key. A plugin that builds a client for a key other than its main one must use `new MoneiClient( $key, new \Monei\Configuration() )`. The main client does not change.

## Service

One service per plugin, named `CustomerService`. Method names follow the
platform's case convention (WooCommerce: snake_case).

A platform where the MONEI account, mode and API key depend on a scope adds
one scope parameter to the methods (Magento: `storeId`). "Current account +
mode" then means the account and mode configured for that scope. Behavior does
not change.

| Method | Behavior |
|---|---|
| `getOrCreateCustomerId(platformCustomerId, email, name, phone): ?string` | Returns the mapped ID for the current account + mode. With no mapping: create, store, return. Any `\Throwable`: log, return `null`. |
| `forgetCustomerId(platformCustomerId, staleCustomerId): void` | Removes the mapping for the current account + mode **only while it still holds `staleCustomerId`**. A newer ID that another request stored meanwhile is kept. |
| `deletePaymentMethod(platformCustomerId, paymentToken): void` | Current account + mode only. No mapping: no API call. Calls `deletePaymentMethod(customerId, paymentToken)`. It never removes the mapping, whatever the result. |
| `deleteCustomer(platformCustomerId): void` | **Every** mapping of the customer, for all accounts and modes. See below. |

`deleteCustomer`, for each mapping row of the customer:

0. If the platform customer has an active platform subscription that charges a MONEI sequence, make no API call for any row: **error** log with each row's MONEI customer ID, account ID and mode, keep the rows, and stop. A customer delete makes MONEI reject every later `recurring` charge on the customer's sequences (check e). "Active" means every status in which the platform will still charge a renewal; each plugin plan lists its statuses.
1. Take the mode from the row. The row matches when the account ID configured for that mode equals the row's account ID and the API key of that mode is not empty. Never find a mode by account ID alone: test and live often share one account ID. With no match: error log, keep the row, go to the next row.
2. Call `delete(moneiCustomerId)` with a client for that mode's key, built with its own `Configuration` (see SDK rules).
3. Success or 404: remove the row. 409 or other error: as in the Errors table.

It needs no current scope, so admin, REST and cron deletes work.

The delete hook reads the mappings from the database, never from a cache that the platform filled before the hook ran (WooCommerce: `wp_delete_user` loads the user meta cache first, so the hook calls `wp_cache_delete( $user_id, 'user_meta' )`). A mapping that a create saved in the meantime is only in the database.

Guests have no `platformCustomerId`. The caller does not call the service for a guest.

## Mapping

Key: (`platformCustomerId`, MONEI account ID, mode `test`|`live`) → MONEI customer ID.

- WooCommerce: user meta `_monei_customer_{accountId}_{mode}`.
- Magento: new table `monei_customer` (`customer_id`, `account_id`, `mode`, `monei_customer_id`), unique on the first three.
- PrestaShop: new table `monei2_customer`, same columns (`id_customer`, `account_id`, `mode`, `monei_customer_id`), unique on the first three.

If the MONEI account ID setting for the current scope is empty, the plugin does
not link: no create call, no `customerId`. It writes one debug log line.

Saving a new mapping must detect a mapping that another request stored first
(two first payments of the same buyer at the same time):

- Magento, PrestaShop: insert; a unique-key conflict means another request won. Read the stored row with a fresh query that bypasses any repository or entity cache, which can still hold the empty result from the first read.
- WooCommerce: `add_user_meta( …, $unique = true )`, then `wp_cache_delete( $user_id, 'user_meta' )`, then read the key again with `get_user_meta( …, true )`. Clear the cache first: a failed unique add does not clear it, so the re-read would return this request's stale value. If the value read is not the new ID, another request won. Remove a duplicate row with the new ID if one was written (`delete_user_meta( $user_id, $key, $newId )`).

After a successful save, check that the platform customer is not being deleted. A delete that runs while the create waits for MONEI reads no mapping, so nothing else deletes the new customer, and its PII outlives the erasure.

- Magento, PrestaShop: after the insert commits, read with a fresh query whether the platform customer still exists. This needs no open transaction around the insert.
- WooCommerce: `wp_delete_user` fires `delete_user` before it deletes the user meta and the user row, so the user still exists while the hook runs. The delete hooks write a marker (option `monei_customer_deleting_{user_id}`, not autoloaded) **before** they read the meta. After the insert, the create path reads the marker and the user row with direct SQL. `get_option()` can answer from a cached absence (`notoptions`) and miss the marker. A marker or a missing user means the delete is under way.

Either the delete hook reads the new row and deletes the customer, or the row was written after that read, so the re-check sees the marker or the missing customer. If both act, one side gets 404, which is handled.

When another request won, follow the "Concurrent create" row in the Errors table. When the save failed and nothing is stored (the re-read is empty, or the insert failed for a reason other than the unique key), follow the "Mapping save fails" row.

## Create request

`CreateCustomerRequest`:

- `email`, `name`: from the platform customer.
- `phone`: from the platform customer. If the customer has no phone field or it is empty, use the billing address of the current order or cart.
- Leave an empty value out of the request (see SDK rules). A buyer with no phone is still linked.
- `metadata`: `{"platform": "woocommerce"|"magento"|"prestashop", "platformCustomerId": "<id as string>"}`.

No other fields. Send the phone as the platform stores it: MONEI accepts local and E.164 formats and stores them as sent (check h).

## When a payment carries `customerId`

On every create-payment call where the platform customer is known. Each item applies only where the platform has that flow:

- checkout payments (all MONEI methods, classic and block/one-page checkout),
- logged-in express checkout,
- the zero-amount "add card" payment (check g: accepted, and the card is saved under the customer),
- the first payment of a subscription.

Never on: guest payments, automatic renewal and recurring charges (they already carry `sequenceId`). A buyer who pays a failed renewal order at checkout makes a normal buyer-present payment, so it carries `customerId`.

Every MONEI payment method the plugins offer accepts `customerId` (check i).

The payload keeps its current inline `customer` block (email, name, phone).

A payment with a `paymentToken` saved before the link also carries `customerId`: MONEI accepts it (check d). The token is not attached to the customer, so a later `deletePaymentMethod` for it gets 404 (debug log) and the token stays live at MONEI. Attaching old tokens is out of scope.

## Errors

All customer errors are logged with the prefix `[MONEI Customers]`. The shopper
never sees them, and they never stop a platform action: every customer code
path catches `\Throwable`, not only API errors.

Every delete error log line holds the MONEI customer ID, the account ID and the
mode, at the level in the table. A platform delete can remove the mapping row
right after the hook (WooCommerce `wp_delete_user` deletes all user meta), so
the log line can be the only record left to clean up by hand.

The plugins log only errors by default. So every case that can leave a MONEI
customer with PII and no mapping (or a mapping that is about to be lost) logs
at **error** level, never at warning.

| Case | Behavior |
|---|---|
| Create fails | Log error. The payment goes ahead without `customerId`. No retry. |
| Concurrent create (saving the mapping finds an ID stored by another request) | Delete the MONEI customer just created (best effort; **error** log with its ID on failure). Return the stored ID. |
| Mapping save fails (nothing stored) | Delete the MONEI customer just created (best effort; **error** log with its ID on failure). Return `null`: the payment goes ahead without `customerId`. |
| Platform customer deleted during create (the re-check after the mapping save) | Delete the new MONEI customer (best effort; **error** log with its ID on failure). Remove the new row. Return `null`: the payment goes ahead without `customerId`. |
| Create-payment fails because the customer no longer exists: `ApiException` with HTTP 404 **and** response body `message` exactly `Customer not found` (check c). Status alone is not enough. | `forgetCustomerId(…, staleId)`, remove `customerId` from the request (see SDK rules), retry the create-payment once. Never a second retry. Any other create-payment error is rethrown unchanged. |
| Delete returns 404 | Debug log. For `deleteCustomer`, remove the row. `deletePaymentMethod` keeps the mapping. |
| `deletePaymentMethod` returns 409 | Warning log. |
| `deletePaymentMethod`, other error | Error log. |
| `deleteCustomer` returns 409 or another error | **Error** log. Keep the row, and go on with the next row. |
| `deleteCustomer` for a customer with an active platform subscription that charges a MONEI sequence | **Error** log per row. Keep all rows, no API call. |
| `deleteCustomer` row with no matching configured key | **Error** log. Keep the row, and go on with the next row. |

## Known limits (accepted)

- A store setup where different scopes use different MONEI accounts:
  - `deletePaymentMethod` uses the current scope's account and mode. A card saved in scope A and deleted while browsing scope B gets 404 (debug log), and the card stays live at A's account. The platform token does not record its origin scope.
  - `deleteCustomer` can only match rows whose account has a key in the scope where the delete runs. Other rows are logged at error level with their MONEI customer ID.
- Neither case is fixed in v8: it needs credentials for every scope, or the scope stored with each token, for a rare setup. A plugin may raise it again with a real merchant case.

## Triggers

| Event | Call |
|---|---|
| Buyer deletes a saved MONEI card | `deletePaymentMethod` |
| Platform deletes the customer | `deleteCustomer` |
| GDPR erasure (Woo: `wp_privacy_personal_data_erasers`; PS: psgdpr `actionDeleteGDPRCustomer`; Magento: the customer delete) | `deleteCustomer` |

A card delete does not need a subscription check. A first payment with `sequence` stores two methods under the customer: the token (`sequence: false`) and a sequence method whose ID is the `sequenceId` (`sequence: true`). The platform token is the first one. Deleting it leaves `recurring` charges working (check e). A customer delete does break them, so `deleteCustomer` has step 0.

## Tests

Each plugin writes these unit tests, with a mocked SDK client. Each one names the bug it catches.

1. A guest payment sends no `customerId` and makes no create call. Bug: orphan customers for guests.
2. An existing mapping is reused with no create call. Bug: a new MONEI customer on every payment.
3. A create error that is an `\Error`, not an `\Exception`, gives a payload without `customerId`, and no mapping is stored. Bug: customer API outage blocks checkout, or an empty ID is stored and reused.
4. Test and live use the **same** account ID. A test-mode mapping is never returned in live mode. Bug: a key without the mode lets live payments send a test customer ID.
5. Delete 404 is logged at debug level: `deleteCustomer` removes the row, and `deletePaymentMethod` keeps the mapping. `deleteCustomer` with another error (for example 500) keeps the row and logs at error level with the MONEI customer ID, account ID and mode. Bug: old unlinked cards log errors or drop the mapping (then the next payment creates a second customer); an outage drops the mapping and leaves the customer at MONEI.
6. A stale customer causes exactly one retry, the second request has no `customerId`, and the mapping is cleared. If the mapping already holds a newer ID, it is kept. A failure of the retry is rethrown. An unrelated error with the **same** HTTP status is rethrown and not retried, and a payload without `customerId` is never retried. Bug: a buyer deleted in the dashboard can never pay again, a retry loop, a retry that throws on `setCustomerId(null)`, or a swallowed payment error.
7. Test and live use the **same** account ID. `deleteCustomer` with a test row and a live row calls delete twice, each with the key of the row's own mode, and removes both rows. A row whose account matches no configured key is kept. When the first row throws an `\Error`, the next row is still deleted. Bug: a live row deleted with the test key gets 404, the row is removed, and the live customer stays at MONEI; or one failing row stops the loop and the other customers are lost with the platform delete.
8. A buyer with no phone gets a create request with no `phone` key, and is linked. Bug: the SDK throws on a `null` phone, so those buyers are never linked.
9. Get the main client, then the client for the other mode, then the main client again. The main client still sends its own key, and the other client sends the other mode's key. Bug: the shared default `Configuration` sends later calls with the wrong key.
10. A concurrent create returns the stored ID, deletes the new MONEI customer, and keeps the winning row. A mapping save that stores nothing returns `null` and deletes the new MONEI customer. Bug: an unmapped MONEI customer with PII that erasure never reaches, a deleted winner row, or an empty `customerId` sent on payments.
11. `deletePaymentMethod` with no mapping makes no API call. Bug: every card delete of an unlinked buyer calls MONEI with an empty customer ID and logs an error.
12. An empty account ID gives no create call, no `customerId` and no stored mapping. Bug: a mapping stored under an empty account that no delete can resolve.
13. `deleteCustomer` for a customer with an active subscription that charges a MONEI sequence makes no delete call, keeps every row, and logs at error level with each MONEI customer ID. The same customer whose subscriptions are all cancelled or expired is deleted. Bug: deleting or erasing a subscriber kills their renewals at MONEI (the charge fails with E999).
14. The platform customer is deleted while the create waits for MONEI (the `create` mock runs the delete hook before it returns). The result is `null`, the new MONEI customer is deleted, and no mapping row is left. Bug: a MONEI customer with PII outlives the platform delete, with no mapping and no log.

## Sandbox results (v7)

Run on 2026-11-06 on MONEI test accounts with SDK 2.13.0: the WooCommerce thread (test card `4444444444444422`, Docker store) and the Magento thread (test card `4444444444444414`). Card payments were completed through the 3DS challenge simulator. The IDs below are test-mode IDs, so anyone can check them again.

- a. `CustomerPaymentMethod.id` is the same string as `payment.paymentToken` (Woo: payment `acd0a023…`, token `55a7ff48…`; Magento: payment `16c24176…`). `deletePaymentMethod(customerId, paymentToken)` succeeds and the list is then empty. A payment with that token then fails with 400 `Invalid payment method`. A second delete gives 404 `Payment method not found`. → Delete by the stored token; no lookup.
- b. Dropped in v5.
- c. A deleted or unknown `customerId` is rejected **at create**, with HTTP 404 and body `{"status":"NotFoundError","statusCode":404,"message":"Customer not found"}`. This happens with and without card data in the request. For comparison, an invalid `paymentToken` gives 400 `Invalid payment method`, and a `deletePaymentMethod` for an unknown token gives 404 `Payment method not found`. → The retry rule stands, and it matches on 404 plus the message. **Still open:** whether the retry works after a first request that carried a one-time monei.js or Apple/Google `paymentToken`. That needs a real checkout. If the token is consumed, the retry helps only the flows without a one-time token, which v6 already accepted.
- d. MONEI accepts `customerId` plus a token saved before the link (Woo: payment `6312032a…` SUCCEEDED; Magento: payment `4c22659d…` SUCCEEDED). The token is not attached to the customer. → No new rule. See "When a payment carries `customerId`".
- e. A first payment with `sequence` under a customer (`4f9a8920…`) lists two methods: the sequence method (`id` = `sequenceId`, `sequence: true`) and the token (`sequence: false`). After the token is deleted, `recurring` on the sequence still **succeeds** (`d9013196…`). After a **customer** delete, `recurring` on its sequence **fails** with `FAILED`, `E999`, "Service internal error" (`5bb2cbfc…`). → Rule: `deleteCustomer` step 0. The E999 is reported to MONEI as an API defect: the API should return a clear error instead.
- f. After a customer delete, a payment with one of its saved tokens fails with 400 `Invalid payment method`. → A customer delete revokes its tokens; no extra step.
- g. A zero-amount `transactionType: VERIF` payment with `customerId` and `generatePaymentToken` is accepted (status AUTHORIZED), and the card is listed under the customer. → The add-card payment carries `customerId`.
- h. `600 123 456`, `600123456` and `+34600123456` are all accepted and stored as sent. → No phone filter.
- i. Card, Bizum, PayPal, Apple Pay, Google Pay, MB WAY and Multibanco all accept `customerId` at create. MB WAY and Multibanco gave status FAILED with and without `customerId`, so `customerId` is not the cause. → No method is excluded.
