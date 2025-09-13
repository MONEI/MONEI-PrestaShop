# Override Deployment Guide

## Overview
This module includes a critical override for the `Order` class that ensures deterministic order reference generation. This override MUST be properly deployed for the module to function correctly.

## Override File
- **Location**: `/modules/monei/override/classes/order/Order.php`
- **Purpose**: Synchronizes order references between MONEI and PrestaShop

## Deployment Methods

### Method 1: Automatic (Recommended)
The override should be automatically installed when the module is installed or reset:
```bash
# PrestaShop 8+
php bin/console prestashop:module reset monei

# PrestaShop 1.7.2-1.7.8
# Reinstall the module via admin panel or manually trigger install
```

### Method 2: Manual Deployment
If automatic deployment fails, manually copy the override:

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
    // Existing override methods...
    
    // MONEI override
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

⚠️ **Critical**: Without this override, order references will not synchronize correctly between MONEI and PrestaShop, leading to payment tracking issues.

⚠️ **Updates**: When updating PrestaShop, overrides may need to be redeployed. Always verify after core updates.

⚠️ **Module Updates**: When updating the MONEI module, the override should be automatically redeployed. Verify after updates.

## Support
If you encounter issues with override deployment, please check:
1. PrestaShop error logs: `/var/logs/`
2. PHP error logs: Check your PHP configuration
3. MONEI module logs in database: `ps_log` table