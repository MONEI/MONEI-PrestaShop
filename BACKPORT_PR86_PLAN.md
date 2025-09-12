# Backporting Plan: PR #86 to PrestaShop 1.7 Branch

## Overview
This document outlines the complete plan for backporting all features from PR #86 (https://github.com/MONEI/MONEI-PrestaShop/pull/86) to the PrestaShop 1.7 branch, ensuring compatibility with PrestaShop 1.7.2 through 1.7.8.

## PR #86 Summary
PR #86 implements critical improvements including:
- Deterministic order reference generation
- Improved Cart to Order functionality
- Enhanced payment history tracking
- Dynamic refund reasons from MONEI SDK
- Better error handling and security fixes
- Always saving PENDING payments for Multibanco support

## Features to Backport

### 1. Order Reference Synchronization System

#### 1.1 MoneiService Changes
**File:** `src/Service/Monei/MoneiService.php`

**Changes:**
- Replace `createMoneiOrderId()` method with deterministic hash generation
- Add new `getCartIdFromPayment()` method with three-tier fallback
- Update `extractCartIdFromMoneiOrderId()` for legacy support
- Fix email handling to use local variable instead of mutating entity
- Change session/trace details handling

**Key Implementation:**
```php
public function createMoneiOrderId(int $cartId)
{
    // Generate deterministic 9-character reference using base36 hash
    $shopId = \Context::getContext()->shop->id;
    $cookieKey = \Configuration::get('PS_COOKIE_KEY');
    $uniqueString = $cartId . '-' . $shopId . '-' . $cookieKey;
    $hash = strtoupper(substr(base_convert(sha1($uniqueString), 16, 36), 0, 9));
    return $hash;
}

public function getCartIdFromPayment(\Monei\Model\Payment $payment)
{
    // 1. Try metadata
    // 2. Try legacy order ID formats
    // 3. Database lookup fallback
}
```

#### 1.2 Order Override
**File:** `override/classes/order/Order.php` (NEW FILE)

**Purpose:** Override PrestaShop's order reference generation to use MONEI's deterministic reference

**Implementation:**
```php
class Order extends OrderCore
{
    public static function generateReference()
    {
        // Check if MONEI reference exists in context
        // Use it if available, otherwise fallback to parent
    }
}
```

### 2. OrderService Improvements

**File:** `src/Service/Order/OrderService.php`

**Changes:**
- Add comprehensive error logging with [MONEI] prefix
- Fix PARTIALLY_REFUNDED state mapping
- Add FAILED -> SUCCEEDED state transition support
- Improve lock handling to prevent leaks
- Store MONEI reference in context before order creation
- Add Throwable catch for unexpected exceptions
- Move handlePostOrderCreation outside try-finally

**Key Changes:**
- Replace `extractCartIdFromMoneiOrderId` with `getCartIdFromPayment`
- Add context storage: `$context->monei_order_reference = $moneiPayment->getOrderId()`
- Improve state transition validation matrix

### 3. Payment Persistence Fixes

**File:** `src/Service/Monei/MoneiService.php`

**Changes in `saveMoneiPayment()` method:**
- Always save payment entity, even for PENDING status
- Only skip history entries for PENDING
- Store sessionDetails instead of traceDetails
- Use `getCartIdFromPayment()` instead of `extractCartIdFromMoneiOrderId()`

**Critical for:** Multibanco and other async payment methods that need to display instructions

### 4. Frontend Controllers Updates

#### 4.1 Redirect Controller
**File:** `controllers/front/redirect.php`

**Changes:**
- Add cart validation before accessing properties
- Add `addQueryParam()` helper method for safe URL manipulation
- Remove Cart to Order feature completely
- Improve error handling and logging
- Fix variable initialization bugs

#### 4.2 CreatePayment Controller
**File:** `controllers/front/createPayment.php`

**Changes:**
- Fix double-read of php://input stream
- Add proper Content-Type headers for JSON responses
- Improve authorization checking
- Better error responses

#### 4.3 Validation Controller
**File:** `controllers/front/validation.php`

**Changes:**
- Improve webhook signature verification (catch Throwable)
- Standardize HTTP response codes
- Fix logging for void return from createOrUpdateOrder

#### 4.4 Confirmation Controller
**File:** `controllers/front/confirmation.php`

**Changes:**
- Update logging format with [MONEI] prefix
- Improve status code handling

### 5. Admin Interface Enhancements

#### 5.1 Admin JavaScript
**File:** `views/js/admin/admin.js`

**Changes:**
- Dynamic refund reasons from MONEI SDK
- Remove hardcoded refund reason list
- Add translation support for refund labels
- Fix Base64 encoding for JSON viewer
- Remove debug console.log statements

**Implementation:**
```javascript
// Get refund reasons from PHP (MONEI SDK)
refundReasons: (typeof MoneiVars !== 'undefined' && MoneiVars.refundReasons) 
    ? MoneiVars.refundReasons 
    : fallbackReasons
```

#### 5.2 Admin Order Display Template
**File:** `views/templates/hook/displayAdminOrder.tpl`

**Changes:**
- Display all payment attempts (not just latest)
- Show payments in reverse chronological order
- Add styled status badges
- Expand JSON viewer by default
- Show session details instead of trace details

### 6. Module Core Updates

**File:** `monei.php`

**Changes:**
- Remove Cart to Order configuration completely
- Add dynamic refund reasons loading from SDK
- Make `copyApplePayDomainVerificationFile()` public
- Add refund error handling with localized messages
- Pass refund reasons to admin JavaScript
- Update validation of required order states

**Key Addition:**
```php
// In getContent() method
$refundReasons = [];
$refReasonClass = '\Monei\Model\PaymentRefundReason';
if (class_exists($refReasonClass)) {
    $reflection = new \ReflectionClass($refReasonClass);
    $constants = $reflection->getConstants();
    foreach ($constants as $name => $value) {
        if ($name === 'OTHER') continue; // Skip invalid
        $refundReasons[] = [
            'value' => $value,
            'label' => $this->l(ucfirst(strtolower(str_replace('_', ' ', $value))))
        ];
    }
}
```

### 7. Database and Installation Updates

#### 7.1 Uninstall Script
**File:** `sql/uninstall.php`

**Changes:**
- Drop ALL tables including customer cards
- Complete data cleanup for GDPR compliance

#### 7.2 Upgrade Script
**File:** `upgrade/upgrade-2.0.9.php` (NEW FILE)

**Purpose:** Comprehensive recovery and upgrade mechanism

**Features:**
- Recreate missing database tables
- Re-register all required hooks
- Recreate admin tabs if missing
- Add missing configuration values
- Validate order states
- Install Order override
- Clear caches

### 8. Translation Updates

**Files:** All translation files in `translations/` directory

**Changes:**
- Add "MONEI refund reason" translation
- Add refund error messages
- Add new status labels
- Update existing translations for clarity

### 9. Documentation Updates

#### 9.1 CLAUDE.md
**File:** `CLAUDE.md`

**Updates:**
- Clarify JavaScript architecture (no build required)
- Add override deployment information
- Update testing instructions with new card input details
- Add Playwright testing guidance for MONEI iframe

#### 9.2 Override Deployment Documentation
**File:** `docs/OVERRIDE_DEPLOYMENT.md` (NEW FILE)

**Content:** Complete guide for Order override deployment and troubleshooting

### 10. Frontend JavaScript Updates

**File:** `views/js/checkout.js` (if present)

**Changes:**
- Improve fetch error handling
- Add Content-Type checking
- Scope error notifications with `monei-payment-alert` class
- Handle non-JSON responses properly

## Compatibility Considerations

### PrestaShop 1.7.2 Specific
- Use numeric values for PrestaShopLogger constants (via getLogLevel method)
- Ensure hookDisplayBackOfficeHeader compatibility
- Handle jQuery timing issues with waitForJQuery wrapper
- Test override installation on older versions

### PrestaShop 1.7.8 Specific
- Ensure compatibility with newer PrestaShop core changes
- Test with updated jQuery versions
- Verify admin theme compatibility

## Implementation Order

1. **Phase 1: Core Services**
   - Update MoneiService with new methods
   - Update OrderService with improvements
   - Add Order override class

2. **Phase 2: Controllers**
   - Update all frontend controllers
   - Fix error handling and security issues

3. **Phase 3: Admin Interface**
   - Update admin JavaScript
   - Update admin templates
   - Add dynamic refund reasons

4. **Phase 4: Module Core**
   - Update monei.php main file
   - Remove Cart to Order feature
   - Add refund handling improvements

5. **Phase 5: Installation & Upgrade**
   - Add upgrade script
   - Update uninstall process
   - Test upgrade path

6. **Phase 6: Frontend & Translations**
   - Update frontend JavaScript
   - Add all translation updates
   - Update documentation

## Testing Checklist

### Functional Tests
- [ ] Successful payment with new reference format
- [ ] Failed payment handling
- [ ] Payment retry from FAILED to SUCCEEDED
- [ ] Multiple payment attempts display in admin
- [ ] Refund with dynamic reasons
- [ ] Refund failure prevents credit slip
- [ ] Multibanco payment instructions display
- [ ] Webhook processing with all status types
- [ ] Cart ID recovery for sandbox payments
- [ ] Legacy payment format compatibility

### Compatibility Tests
- [ ] PrestaShop 1.7.2.4 installation
- [ ] PrestaShop 1.7.8 installation
- [ ] Override deployment on both versions
- [ ] Admin asset loading on both versions
- [ ] jQuery compatibility
- [ ] Multi-shop functionality
- [ ] Currency restrictions

### Security Tests
- [ ] URL injection prevention
- [ ] Webhook signature verification
- [ ] No sensitive data in logs
- [ ] Proper error message sanitization

### Performance Tests
- [ ] Concurrent order creation (no reference collisions)
- [ ] Lock handling under load
- [ ] Database query optimization
- [ ] Cache clearing effectiveness

## Risk Assessment

### High Risk Areas
1. **Order Override** - May conflict with other modules
2. **Reference Generation Change** - Affects existing order flow
3. **Database Schema Changes** - Requires careful migration

### Mitigation Strategies
1. Extensive testing on multiple PrestaShop versions
2. Gradual rollout with feature flags if needed
3. Comprehensive logging for debugging
4. Rollback plan with data preservation

## Post-Implementation

### Monitoring
- Track order creation success rates
- Monitor refund processing
- Check for reference collisions
- Review error logs for patterns

### Documentation
- Update user documentation
- Create troubleshooting guide
- Document override conflicts resolution
- Add developer notes for future maintenance

## Notes

### Removed Features
- **Cart to Order**: Completely removed due to PrestaShop architecture incompatibility

### Critical Changes
- **PENDING Payment Storage**: Now always saved (required for Multibanco)
- **Reference Format**: Changed from `cartId-m-suffix` to 9-character hash
- **Session vs Trace Details**: Now storing customer session info instead of server trace

### Backward Compatibility
- Legacy order ID formats still supported
- Database fallback for missing metadata
- Graceful degradation for missing features

## Success Criteria

1. All features from PR #86 working on PrestaShop 1.7.2 and 1.7.8
2. No regression in existing functionality
3. Improved error handling and logging
4. Better admin interface for payment management
5. Successful handling of all payment scenarios
6. Proper translation support
7. Clean upgrade path from current version