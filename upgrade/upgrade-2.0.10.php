<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.0.10 - Update order state translations and deduplicate states
 */
function upgrade_module_2_0_10($module)
{
    try {
        // 1. Update order state translations for all languages
        updateOrderStateTranslations();

        // 2. Deduplicate order states - use PrestaShop defaults where possible
        deduplicateOrderStates();

        // 3. Clean up any orphaned states
        cleanupOrphanedStates($module);

        \Monei::logDebug('[MONEI] Upgrade to 2.0.10 completed successfully');

        return true;
    } catch (Exception $e) {
        \Monei::logDebug('[MONEI] Upgrade to 2.0.10 failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Update order state translations for all existing MONEI states
 */
function updateOrderStateTranslations()
{
    // Map config keys to their English names
    $stateMapping = [
        'MONEI_STATUS_PENDING' => 'Awaiting payment',
        'MONEI_STATUS_AUTHORIZED' => 'Payment authorized',
        'MONEI_STATUS_SUCCEEDED' => 'Payment accepted',
        'MONEI_STATUS_FAILED' => 'Payment failed',
        'MONEI_STATUS_REFUNDED' => 'Refunded',
        // Note: MONEI_STATUS_PARTIALLY_REFUNDED uses the same PS_OS_REFUND state as MONEI_STATUS_REFUNDED
    ];

    foreach ($stateMapping as $configKey => $englishName) {
        $stateId = (int) Configuration::get($configKey);

        if ($stateId > 0) {
            $orderState = new OrderState($stateId);

            if (Validate::isLoadedObject($orderState)) {
                // Update translations for all languages
                foreach (Language::getLanguages() as $language) {
                    $isoCode = Tools::strtolower($language['iso_code']);
                    $translation = Monei::getOrderStatusTranslation($englishName, $isoCode);

                    $orderState->name[$language['id_lang']] = $translation;
                }

                $orderState->save();

                \Monei::logDebug('[MONEI] Updated translations for state: ' . $englishName);
            }
        }
    }
}

/**
 * Deduplicate order states and use PrestaShop defaults where possible
 */
function deduplicateOrderStates()
{
    // Use PrestaShop default refund state for both refund states
    $refundStateId = (int) Configuration::get('PS_OS_REFUND');

    if ($refundStateId > 0) {
        Configuration::updateValue('MONEI_STATUS_REFUNDED', $refundStateId);
        Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', $refundStateId);

        \Monei::logDebug('[MONEI] Using PrestaShop default refund state (ID: ' . $refundStateId . ')');
    }

    // Check if we can use PrestaShop defaults for other states
    $stateDefaults = [
        'MONEI_STATUS_SUCCEEDED' => 'PS_OS_PAYMENT',
        'MONEI_STATUS_FAILED' => 'PS_OS_ERROR',
        'MONEI_STATUS_PENDING' => 'PS_OS_PREPARATION',
    ];

    foreach ($stateDefaults as $moneiConfig => $psDefault) {
        $psStateId = (int) Configuration::get($psDefault);

        if ($psStateId > 0) {
            $currentStateId = (int) Configuration::get($moneiConfig);

            // Only update if different to avoid unnecessary changes
            if ($currentStateId !== $psStateId) {
                Configuration::updateValue($moneiConfig, $psStateId);

                \Monei::logDebug('[MONEI] Updated ' . $moneiConfig . ' to use PrestaShop default state (ID: ' . $psStateId . ')');
            }
        }
    }
}

/**
 * Clean up any orphaned MONEI states that are no longer used
 * WARNING: Only deletes states that are not referenced in:
 * - orders.current_state
 * - order_history.id_order_state
 * - order_state_lang.id_order_state
 * This prevents breaking order history viewing
 */
function cleanupOrphanedStates($module)
{
    // Get all state IDs currently configured
    $configuredStates = [
        (int) Configuration::get('MONEI_STATUS_PENDING'),
        (int) Configuration::get('MONEI_STATUS_SUCCEEDED'),
        (int) Configuration::get('MONEI_STATUS_FAILED'),
        (int) Configuration::get('MONEI_STATUS_REFUNDED'),
        (int) Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED'),
        (int) Configuration::get('MONEI_STATUS_AUTHORIZED'),
    ];

    // Remove duplicates and invalid IDs
    $configuredStates = array_filter(array_unique($configuredStates));

    // Find all MONEI module states in database
    $sql = 'SELECT DISTINCT os.`id_order_state`
            FROM `' . _DB_PREFIX_ . 'order_state` os
            WHERE os.`module_name` = \'' . pSQL($module->name) . '\'';

    $allMoneiStates = Db::getInstance()->executeS($sql);

    if ($allMoneiStates) {
        foreach ($allMoneiStates as $row) {
            $stateId = (int) $row['id_order_state'];

            // If this state is not in our configured states, it might be orphaned
            if (!in_array($stateId, $configuredStates)) {
                // Check if this state is referenced anywhere in the database
                $isStateInUse = false;

                // Check current_state in orders table
                $ordersUsing = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'orders`
                     WHERE `current_state` = ' . $stateId
                );

                if ($ordersUsing > 0) {
                    $isStateInUse = true;
                }

                // Check order_history table - CRITICAL CHECK
                if (!$isStateInUse) {
                    $historyUsing = (int) Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history`
                         WHERE `id_order_state` = ' . $stateId
                    );

                    if ($historyUsing > 0) {
                        $isStateInUse = true;
                    }
                }

                // Check order_state_lang table for translations
                if (!$isStateInUse) {
                    $langUsing = (int) Db::getInstance()->getValue(
                        'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_state_lang`
                         WHERE `id_order_state` = ' . $stateId
                    );

                    if ($langUsing > 0) {
                        $isStateInUse = true;
                    }
                }

                if (!$isStateInUse) {
                    // Only delete if truly orphaned (not referenced anywhere)
                    $orderState = new OrderState($stateId);
                    if (Validate::isLoadedObject($orderState)) {
                        $orderState->delete();

                        \Monei::logDebug('[MONEI] Deleted truly orphaned state (ID: ' . $stateId . ')');
                    }
                } else {
                    // State is still referenced, keep it even if not configured
                    // This is important to preserve order history integrity
                    \Monei::logDebug('[MONEI] Keeping unconfigured state (ID: ' . $stateId
                        . ') as it is still referenced in database');
                }
            }
        }
    }
}
