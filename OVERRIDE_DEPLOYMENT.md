# Override Deployment Guide

## Overview
This module uses different approaches to synchronize order references between MONEI and PrestaShop depending on your PrestaShop version:

- **PrestaShop 8.1+**: Uses the `actionGenerateDocumentReference` hook (no override needed)
- **PrestaShop 8.0.x**: Uses an Order class override (automatically installed)

The module automatically detects your PrestaShop version and uses the appropriate method during installation.

## Override File (PrestaShop 8.0.x only)
- **Location**: `/modules/monei/override/classes/order/Order.php`
- **Purpose**: Synchronizes order references between MONEI and PrestaShop
- **Auto-installed**: Yes, during module installation on PrestaShop 8.0.x

## Why This Synchronization Is Necessary

Without this override:
- PrestaShop generates random order references (e.g., `XKBKADJT`)
- MONEI has its own order ID format (e.g., `1-7-1234567890`)
- These references don't match, causing:
  - Confusion for merchants looking at orders in both systems
  - Inability to use order reference for cart lookup in webhooks
  - Inconsistent reporting between systems

With this override:
- PrestaShop order reference = MONEI order ID
- Perfect synchronization between both systems
- Simplified debugging and support

## Known Limitation

**PrestaShop's override merging system strips PHPDoc comments and inline comments from other modules' overrides.** This is a known PrestaShop limitation, not specific to this module.

If you have other modules that override the Order class and this causes issues:
1. Manually merge the overrides (see instructions below)
2. Document the merged changes separately
3. Contact the other module vendors about the limitation

We understand this is not ideal, but the alternative (mismatched references) creates more problems than it solves.

## Version-Specific Behavior

### PrestaShop 8.1+ (Recommended)
- **Method**: Uses `actionGenerateDocumentReference` hook
- **Override**: Not needed
- **Installation**: Hook is automatically registered during module installation
- **Benefits**:
  - No file system modifications
  - No conflicts with other modules
  - Cleaner upgrade path

### PrestaShop 8.0.x
- **Method**: Uses Order class override
- **Override**: Automatically installed to `/override/classes/order/Order.php`
- **Installation**: Override is copied during module installation
- **Note**: PrestaShop 8.0.x doesn't support the `actionGenerateDocumentReference` hook

## Deployment Methods

### Method 1: Automatic (Recommended)
The appropriate method (hook or override) is automatically selected based on your PrestaShop version:
```bash
# PrestaShop 8+
php bin/console prestashop:module reset monei

# Or reinstall via admin panel
```

### Method 2: Manual Deployment (PrestaShop 8.0.x only)
If automatic deployment fails on PrestaShop 8.0.x, manually copy the override:

1. **Copy the override file:**
   ```bash
   cp modules/monei/override/classes/order/Order.php override/classes/order/Order.php
   ```

2. **Clear the class cache:**
   ```bash
   rm -f var/cache/*/class_index.php
   # For PrestaShop 1.7.2-1.7.8:
   rm -f cache/class_index.php
   ```

3. **Clear all caches:**
   ```bash
   # PrestaShop 8+
   php bin/console cache:clear

   # PrestaShop 1.7.2-1.7.8
   rm -rf cache/smarty/compile/*
   rm -rf cache/smarty/cache/*
   ```

## Verification

### Check if Override is Active
1. Navigate to **Advanced Parameters > Performance** in admin panel
2. Click on "Debug mode" section
3. Look for "Overrides" - it should be enabled
4. Check if `/override/classes/order/Order.php` exists

### Test Override Functionality
1. Create a test order
2. Check MONEI logs for deterministic reference generation
3. Verify order reference matches between MONEI and PrestaShop

## Troubleshooting

### Override Not Loading
If the override is not being applied:

1. **Check file permissions:**
   ```bash
   chmod 644 override/classes/order/Order.php
   ```

2. **Verify override is enabled:**
   - Go to Advanced Parameters > Performance
   - Ensure "Disable all overrides" is set to "No"

3. **Force regenerate class index:**
   ```bash
   # Delete the index
   rm -f var/cache/*/class_index.php

   # Access any page to regenerate
   ```

### Conflicts with Other Modules
If another module overrides the Order class:

1. **Check existing overrides:**
   ```bash
   ls -la override/classes/order/
   ```

2. **Merge overrides manually:**
   - Open the existing `Order.php` override
   - Add the MONEI `generateReference()` method
   - Ensure both overrides coexist

Example merged override:
```php
class Order extends OrderCore
{
    // Existing override methods from other module...

    // MONEI override - DO NOT REMOVE
    public static function generateReference()
    {
        $context = Context::getContext();
        if (isset($context->monei_order_reference) && !empty($context->monei_order_reference)) {
            return $context->monei_order_reference;
        }
        return parent::generateReference();
    }

    // Other existing methods...
}
```

## Important Notes

⚠️ **Critical**: Order reference synchronization is required for the module to function correctly. The module automatically uses the appropriate method based on your PrestaShop version.

⚠️ **PrestaShop 8.1+ Upgrade**: If you upgrade from PrestaShop 8.0.x to 8.1+, you should:
1. Uninstall and reinstall the MONEI module to switch from override to hook
2. Or manually remove the override file if it exists: `/override/classes/order/Order.php`

⚠️ **PrestaShop 8.0.x**:
- Override is automatically installed during module installation
- When updating PrestaShop, overrides may need to be redeployed
- Comment stripping: PrestaShop's override system will strip comments from other modules' overrides when merging

⚠️ **Module Updates**: When updating the MONEI module, the appropriate method (hook or override) is automatically configured based on your PrestaShop version.

## Support
If you encounter issues with override deployment, please check:
1. PrestaShop error logs: `/var/logs/`
2. PHP error logs: Check your PHP configuration
3. MONEI module logs in database: `ps_log` table
