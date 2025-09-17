<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.0.9 - Clean up deprecated configuration and ensure overrides are properly installed
 */
function upgrade_module_2_0_9($module)
{
    // Clean up deprecated MONEI_CART_TO_ORDER configuration
    Configuration::deleteByName('MONEI_CART_TO_ORDER');

    // Ensure override is properly installed
    try {
        // Check if override directory exists, create if not
        $overrideDir = _PS_OVERRIDE_DIR_ . 'classes/order/';
        if (!is_dir($overrideDir)) {
            mkdir($overrideDir, 0755, true);
        }

        // Copy the Order override if it doesn't exist or is outdated
        $sourceOverride = _PS_MODULE_DIR_ . $module->name . '/override/classes/order/Order.php';
        $destOverride = _PS_OVERRIDE_DIR_ . 'classes/order/Order.php';

        if (file_exists($sourceOverride)) {
            // Check if destination exists and compare content
            $needsUpdate = true;
            if (file_exists($destOverride)) {
                $destHash = sha1_file($destOverride);
                $srcHash = sha1_file($sourceOverride);
                $needsUpdate = ($destHash !== $srcHash);
            }

            if ($needsUpdate) {
                // Backup existing override if present
                if (file_exists($destOverride)) {
                    copy($destOverride, $destOverride . '.backup.' . time());
                }

                // Copy our override
                copy($sourceOverride, $destOverride);

                // Clear cache to ensure override is loaded
                Tools::clearCache();
                Tools::clearCompileCache();

                // Log the installation
                \Monei::logDebug('[MONEI] Order override installed during upgrade to 2.0.9');
            }
        }
    } catch (Exception $e) {
        // Log error but don't fail the upgrade
        \Monei::logWarning('[MONEI] Warning: Could not install Order override during upgrade: ' . $e->getMessage());
    }

    return true;
}
