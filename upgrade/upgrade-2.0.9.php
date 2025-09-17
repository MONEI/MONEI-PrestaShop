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
                    $backupFile = $destOverride . '.backup.' . time();
                    if (!@rename($destOverride, $backupFile)) {
                        throw new Exception('Failed to backup existing override: ' . $destOverride . ' -> ' . $backupFile);
                    }
                }

                // Copy our override
                if (!@copy($sourceOverride, $destOverride)) {
                    // Restore backup if copy failed
                    if (isset($backupFile) && file_exists($backupFile)) {
                        @rename($backupFile, $destOverride);
                    }

                    throw new Exception('Failed to copy override file: ' . $sourceOverride . ' -> ' . $destOverride);
                }

                // Clear cache to ensure override is loaded
                Tools::clearCache();
                Tools::clearSmartyCache();

                // Log the installation
                Monei::logDebug('[MONEI] Order override installed during upgrade to 2.0.9');
            }
        }
    } catch (Throwable $e) {
        // Log error but don't fail the upgrade
        Monei::logError('[MONEI] Could not install Order override during upgrade: ' . $e->getMessage());
    }

    return true;
}
