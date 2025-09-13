<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.0.12 - Add missing is_captured column to monei2_payment table
 */
function upgrade_module_2_0_12($module)
{
    try {
        // Check if the is_captured column exists
        $sql = "SHOW COLUMNS FROM `" . _DB_PREFIX_ . "monei2_payment` LIKE 'is_captured'";
        $result = Db::getInstance()->executeS($sql);

        if (empty($result)) {
            // Column doesn't exist, add it
            $sql = "ALTER TABLE `" . _DB_PREFIX_ . "monei2_payment`
                    ADD COLUMN `is_captured` TINYINT(1) DEFAULT 0 AFTER `status`";

            if (!Db::getInstance()->execute($sql)) {
                PrestaShopLogger::addLog(
                    '[MONEI] Failed to add is_captured column during upgrade to 2.0.12',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
                return false;
            }

            PrestaShopLogger::addLog(
                '[MONEI] Successfully added is_captured column during upgrade to 2.0.12',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
        } else {
            PrestaShopLogger::addLog(
                '[MONEI] is_captured column already exists, skipping addition in upgrade to 2.0.12',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
        }

        return true;
    } catch (Exception $e) {
        PrestaShopLogger::addLog(
            '[MONEI] Upgrade to 2.0.12 failed: ' . $e->getMessage(),
            PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
        );

        return false;
    }
}