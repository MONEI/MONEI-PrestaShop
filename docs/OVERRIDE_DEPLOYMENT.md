# Order Override Deployment Guide

## Overview
MONEI module v2.0.10+ uses a PrestaShop Order class override to synchronize order references between PrestaShop and MONEI payment system. This ensures consistent order tracking across both platforms.

## Files Involved
- **Source**: `/modules/monei/override/classes/order/Order.php`
- **Destination**: `/override/classes/order/Order.php`

## Automatic Installation
The override is automatically installed during:
1. **Module Installation**: First-time installation copies the override
2. **Module Upgrade**: Upgrade to v2.0.10+ installs/updates the override
3. **Module Reset**: Reset operation reinstalls the override

## Manual Installation
If automatic installation fails:

1. **Copy the override file**:
   ```bash
   cp modules/monei/override/classes/order/Order.php override/classes/order/Order.php
   ```

2. **Clear PrestaShop cache**:
   ```bash
   rm -rf var/cache/*
   ```

3. **Regenerate autoload** (PrestaShop 1.7+):
   ```bash
   php bin/console cache:clear
   ```

## Verification
To verify the override is active:

1. **Check file exists**:
   ```bash
   ls -la override/classes/order/Order.php
   ```

2. **Verify content**:
   ```bash
   grep "monei_order_reference" override/classes/order/Order.php
   ```

3. **Check PrestaShop recognizes it**:
   - Go to Advanced Parameters → Performance
   - Click "Clear cache"
   - Create a test order and verify the reference format

## Troubleshooting

### Override Not Loading
1. Clear all caches (file cache, opcode cache)
2. Check file permissions (must be readable by web server)
3. Verify no syntax errors in the override file
4. Check for conflicts with other module overrides

### Conflicts with Other Modules
If another module overrides the Order class:
1. Manually merge the overrides
2. Ensure both `generateReference()` methods are combined
3. Test thoroughly after merging

### Uninstallation
The override is automatically removed when:
- The module is uninstalled (if no other overrides exist in the file)
- A warning is logged if other overrides are detected

## Important Notes
- **Cache Clearing Required**: Always clear cache after override changes
- **Multi-shop**: Override applies to all shops in multi-shop setup
- **Performance**: Minimal impact, only affects order creation
- **Backward Compatibility**: Falls back to PrestaShop's default if override fails