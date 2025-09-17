<?php
/**
 * MONEI Module Upgrade Script to v1.7.5
 *
 * This upgrade script implements comprehensive recovery features from PR #86:
 * - Recreates missing database tables
 * - Re-registers all required hooks
 * - Recreates admin tabs if missing
 * - Adds missing configuration values
 * - Validates order states
 * - Installs Order override for reference synchronization
 * - Ensures Apple Pay verification file exists
 * - Cleans up deprecated configurations
 * - Regenerates .htaccess and clears caches
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to version 1.7.5
 *
 * @param object $module Module instance
 *
 * @return bool Success
 */
function upgrade_module_1_7_5($module)
{
    try {
        // 1. Remove deprecated Cart to Order configuration
        Configuration::deleteByName('MONEI_CART_TO_ORDER');

        // 2. Ensure all required database tables exist
        $sql = [];

        // Create payment table if not exists
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_payment` (
            `id` VARCHAR(255) NOT NULL,
            `id_cart` INT(11) NOT NULL,
            `id_order` INT(11) DEFAULT 0,
            `id_order_monei` VARCHAR(255) DEFAULT NULL,
            `amount` INT(11) DEFAULT NULL,
            `refunded_amount` INT(11) DEFAULT 0,
            `currency` VARCHAR(3) DEFAULT NULL,
            `status` VARCHAR(255) DEFAULT NULL,
            `status_code` VARCHAR(255) DEFAULT NULL,
            `authorization_code` VARCHAR(255) DEFAULT NULL,
            `is_captured` TINYINT(1) DEFAULT 0,
            `date_add` DATETIME DEFAULT NULL,
            `date_upd` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id`),
            INDEX `id_cart` (`id_cart`),
            INDEX `id_order` (`id_order`),
            INDEX `id_order_monei` (`id_order_monei`),
            INDEX `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Create history table if not exists
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_history` (
            `id_history` INT(11) NOT NULL AUTO_INCREMENT,
            `id_payment` VARCHAR(255) NOT NULL,
            `response` TEXT DEFAULT NULL,
            `status` VARCHAR(255) DEFAULT NULL,
            `status_code` VARCHAR(255) DEFAULT NULL,
            `date_add` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_history`),
            INDEX `id_payment` (`id_payment`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Create refund table if not exists
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_refund` (
            `id_refund` INT(11) NOT NULL AUTO_INCREMENT,
            `id_history` INT(11) NOT NULL,
            `id_employee` INT(11) DEFAULT 0,
            `reason` VARCHAR(255) DEFAULT NULL,
            `amount` INT(11) DEFAULT NULL,
            `date_add` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_refund`),
            INDEX `id_history` (`id_history`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Create customer card table if not exists (preserve existing cards)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_customer_card` (
            `id_customer_card` INT(11) NOT NULL AUTO_INCREMENT,
            `id_customer` INT(11) NOT NULL,
            `tokenized` VARCHAR(255) NOT NULL,
            `brand` VARCHAR(255) DEFAULT NULL,
            `country` VARCHAR(3) DEFAULT NULL,
            `last_four` VARCHAR(4) DEFAULT NULL,
            `expiration` VARCHAR(11) DEFAULT NULL,
            `date_add` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_customer_card`),
            INDEX `id_customer` (`id_customer`),
            INDEX `tokenized` (`tokenized`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Create order_payment tracking table if not exists
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_order_payment` (
            `id_order` INT(11) NOT NULL,
            `id_payment` VARCHAR(255) NOT NULL,
            `date_add` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_order`, `id_payment`),
            INDEX `id_order` (`id_order`),
            INDEX `id_payment` (`id_payment`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Create admin order message table if not exists
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_admin_order_message` (
            `id_message` INT(11) NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) NOT NULL,
            `message` TEXT DEFAULT NULL,
            `date_add` DATETIME DEFAULT NULL,
            PRIMARY KEY (`id_message`),
            INDEX `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                Monei::logError('[MONEI] Upgrade 1.7.5 - Failed to create table: ' . $query);

                return false;
            }
        }

        // 3. Re-register all required hooks
        $hooks = [
            'header',
            'displayHeader',
            'displayBackOfficeHeader',
            'paymentOptions',
            'paymentReturn',
            'displayAdminOrder',
            'actionOrderSlipAdd',
            'displayAdminOrderTabContent',
            'displayAdminOrderTabLink',
            'displayOrderConfirmation',
            'displayPaymentByBinaries',
            'displayPaymentReturn',
            'actionOrderStatusPostUpdate',
            'actionFrontControllerSetMedia',
        ];

        foreach ($hooks as $hookName) {
            if (!$module->isRegisteredInHook($hookName)) {
                $module->registerHook($hookName);
            }
        }

        // 4. Recreate admin tabs if missing
        $tabId = (int) Tab::getIdFromClassName('AdminMonei');
        if (!$tabId) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = 'AdminMonei';
            $tab->name = [];
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = 'MONEI';
            }
            $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf');
            $tab->module = $module->name;
            $tab->add();
        }

        // 5. Add missing configuration values
        $defaultConfigs = [
            'MONEI_PRODUCTION_MODE' => false,
            'MONEI_SHOW_LOGO' => true,
            'MONEI_ALLOW_CARD' => true,
            'MONEI_ALLOW_BIZUM' => false,
            'MONEI_ALLOW_APPLE' => false,
            'MONEI_ALLOW_GOOGLE' => false,
            'MONEI_ALLOW_PAYPAL' => false,
            'MONEI_ALLOW_MULTIBANCO' => false,
            'MONEI_ALLOW_MBWAY' => false,
            'MONEI_TOKENIZE' => false,
            'MONEI_SWITCH_REFUNDS' => true,
            'MONEI_PAYMENT_ACTION' => 'sale',
        ];

        foreach ($defaultConfigs as $key => $defaultValue) {
            if (!Configuration::hasKey($key)) {
                Configuration::updateValue($key, $defaultValue);
            }
        }

        // 6. Clean up old MONEI order states to avoid duplication
        // Use PrestaShop's default states when available to avoid creating duplicates
        // Don't rely on hard-coded IDs as they can vary between installations
        $psPaymentState = Configuration::get('PS_OS_PAYMENT');
        $psPreparationState = Configuration::get('PS_OS_PREPARATION');
        $psRefundState = Configuration::get('PS_OS_REFUND');
        $psErrorState = Configuration::get('PS_OS_ERROR');

        // Always prefer PrestaShop's default states when they exist
        if ($psPaymentState) {
            Configuration::updateValue('MONEI_STATUS_SUCCEEDED', $psPaymentState);
        }
        if ($psPreparationState) {
            Configuration::updateValue('MONEI_STATUS_PENDING', $psPreparationState);
        }
        if ($psRefundState) {
            Configuration::updateValue('MONEI_STATUS_REFUNDED', $psRefundState);
            Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', $psRefundState);
        }
        if ($psErrorState) {
            Configuration::updateValue('MONEI_STATUS_FAILED', $psErrorState);
        }

        // Clean up any old MONEI-created duplicate states
        $oldMoneiStates = Db::getInstance()->executeS(
            'SELECT DISTINCT os.id_order_state
            FROM ' . _DB_PREFIX_ . "order_state os
            WHERE os.module_name = 'monei'
            AND os.id_order_state NOT IN (" . (int) Configuration::get('MONEI_STATUS_AUTHORIZED') . ')'
        );

        foreach ($oldMoneiStates as $oldState) {
            $stateId = (int) $oldState['id_order_state'];

            // Check if this state is used by any orders (current or historical)
            $isUsedInOrders = Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'orders
                WHERE current_state = ' . $stateId
            );

            $isUsedInHistory = Db::getInstance()->getValue(
                'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'order_history
                WHERE id_order_state = ' . $stateId
            );

            $isUsed = $isUsedInOrders || $isUsedInHistory;

            if (!$isUsed) {
                // Safe to delete unused MONEI state
                $orderState = new OrderState($stateId);
                if (Validate::isLoadedObject($orderState) && $orderState->module_name == 'monei') {
                    $orderState->delete();
                }
            }
        }

        // 7. Create only the MONEI-specific order states (not available in PrestaShop by default)
        $orderStates = [];

        // Always need the authorized state as it's MONEI-specific
        if (!Configuration::get('MONEI_STATUS_AUTHORIZED')) {
            $orderStates['MONEI_STATUS_AUTHORIZED'] = [
                'name' => 'Payment authorized',
                'color' => '#4169E1',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ];
        }

        // Create custom states only if PrestaShop defaults don't exist or weren't set above
        if (!Configuration::get('MONEI_STATUS_PENDING')) {
            $orderStates['MONEI_STATUS_PENDING'] = [
                'name' => 'Awaiting payment',
                'color' => '#8961A5',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ];
        }
        if (!Configuration::get('MONEI_STATUS_SUCCEEDED')) {
            $orderStates['MONEI_STATUS_SUCCEEDED'] = [
                'name' => 'Payment accepted',
                'color' => '#32CD32',
                'send_email' => true,
                'paid' => true,
                'invoice' => true,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ];
        }
        if (!Configuration::get('MONEI_STATUS_FAILED')) {
            $orderStates['MONEI_STATUS_FAILED'] = [
                'name' => 'Payment error',
                'color' => '#DC143C',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => false,
                'delivery' => false,
            ];
        }
        if (!Configuration::get('MONEI_STATUS_REFUNDED')) {
            $orderStates['MONEI_STATUS_REFUNDED'] = [
                'name' => 'Refunded',
                'color' => '#ec2e15',
                'send_email' => true,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => false,
                'delivery' => false,
            ];
        }

        // Load translation methods if needed
        if (!class_exists('Monei')) {
            require_once dirname(__FILE__) . '/../monei.php';
        }

        // Special handling: Both REFUNDED and PARTIALLY_REFUNDED should use the same state
        $refundedStateId = null;

        foreach ($orderStates as $configKey => $stateData) {
            $stateId = Configuration::get($configKey);

            // If this is PARTIALLY_REFUNDED and we already created REFUNDED, use the same state
            if ($configKey === 'MONEI_STATUS_PARTIALLY_REFUNDED' && $refundedStateId) {
                Configuration::updateValue($configKey, $refundedStateId);

                continue;
            }

            if (!$stateId) {
                // Create the order state
                $orderState = new OrderState();
                $orderState->name = [];
                foreach (Language::getLanguages() as $language) {
                    $iso_code = Tools::strtolower($language['iso_code']);
                    // Use centralized translation method to get the correct translation
                    $orderState->name[$language['id_lang']] = Monei::getOrderStatusTranslation($stateData['name'], $iso_code);
                }
                $orderState->color = $stateData['color'];
                $orderState->send_email = $stateData['send_email'];
                $orderState->paid = $stateData['paid'];
                $orderState->invoice = $stateData['invoice'];
                $orderState->shipped = $stateData['shipped'];
                $orderState->logable = $stateData['logable'];
                $orderState->delivery = $stateData['delivery'];
                $orderState->hidden = false;
                $orderState->unremovable = false;
                $orderState->module_name = $module->name;

                if ($orderState->add()) {
                    Configuration::updateValue($configKey, (int) $orderState->id);

                    // Store the refunded state ID for reuse
                    if ($configKey === 'MONEI_STATUS_REFUNDED') {
                        $refundedStateId = (int) $orderState->id;
                    }

                    // Copy logo
                    @copy(
                        dirname(__FILE__) . '/../logo.gif',
                        _PS_ORDER_STATE_IMG_DIR_ . (int) $orderState->id . '.gif'
                    );
                }
            } else {
                // Validate existing state
                $orderState = new OrderState($stateId);
                if (!Validate::isLoadedObject($orderState)) {
                    // State doesn't exist, recreate it
                    Configuration::deleteByName($configKey);

                    // Recursive call will recreate it
                    return upgrade_module_1_7_5($module);
                }

                // Store the refunded state ID if it exists
                if ($configKey === 'MONEI_STATUS_REFUNDED') {
                    $refundedStateId = $stateId;
                }
            }
        }

        // 8. Update order status translations for all MONEI statuses
        // Use centralized translation methods from main module

        // Map config keys to status names
        $statusMapping = [
            'MONEI_STATUS_PENDING' => 'Awaiting payment',
            'MONEI_STATUS_SUCCEEDED' => 'Payment accepted',
            'MONEI_STATUS_FAILED' => 'Payment error',
            'MONEI_STATUS_REFUNDED' => 'Refunded',
            'MONEI_STATUS_PARTIALLY_REFUNDED' => 'Refunded',  // Same as REFUNDED
            'MONEI_STATUS_AUTHORIZED' => 'Payment authorized',
        ];

        // Update translations for each status
        foreach ($statusMapping as $configKey => $statusName) {
            $stateId = Configuration::get($configKey);
            if ($stateId) {
                foreach (Language::getLanguages(false) as $language) {
                    $iso_code = Tools::strtolower($language['iso_code']);
                    $id_lang = (int) $language['id_lang'];

                    // Get translation using centralized method
                    $translation = Monei::getOrderStatusTranslation($statusName, $iso_code);

                    // Update the order state name
                    Db::getInstance()->execute(
                        'INSERT INTO `' . _DB_PREFIX_ . 'order_state_lang`
                        (`id_order_state`, `id_lang`, `name`, `template`)
                        VALUES (' . (int) $stateId . ', ' . (int) $id_lang . ',
                                \'' . pSQL($translation) . '\', \'\')
                        ON DUPLICATE KEY UPDATE
                        `name` = \'' . pSQL($translation) . '\''
                    );
                }
            }
        }

        // 9. Install Order override for reference synchronization
        try {
            // Check if override exists
            $overrideSource = _PS_MODULE_DIR_ . $module->name . '/override/classes/order/Order.php';
            $overrideDestination = _PS_OVERRIDE_DIR_ . 'classes/order/Order.php';

            if (file_exists($overrideSource)) {
                // Install the override using PrestaShop's method
                if (method_exists($module, 'installOverrides')) {
                    $module->installOverrides();
                } else {
                    // Manual installation for older versions
                    if (!file_exists(dirname($overrideDestination))) {
                        mkdir(dirname($overrideDestination), 0777, true);
                    }

                    if (!file_exists($overrideDestination)) {
                        copy($overrideSource, $overrideDestination);
                    }

                    // Clear class cache
                    if (file_exists(_PS_CACHE_DIR_ . 'class_index.php')) {
                        unlink(_PS_CACHE_DIR_ . 'class_index.php');
                    }
                }
            }
        } catch (Exception $e) {
            Monei::logWarning('[MONEI] Upgrade 1.7.5 - Failed to install Order override: ' . $e->getMessage());
            // Continue with upgrade even if override fails
        }

        // 10. Ensure Apple Pay domain verification file exists
        if (method_exists($module, 'copyApplePayDomainVerificationFile')) {
            try {
                // Method is now public, call directly
                if (!$module->copyApplePayDomainVerificationFile()) {
                    throw new Exception('copyApplePayDomainVerificationFile returned false');
                }
            } catch (Exception $e) {
                // If the method fails, try directly
                $sourceFile = _PS_MODULE_DIR_ . $module->name . '/files/apple-developer-merchantid-domain-association';
                $destinationFile = _PS_ROOT_DIR_ . '/.well-known/apple-developer-merchantid-domain-association';

                if (file_exists($sourceFile)) {
                    $wellKnownDir = _PS_ROOT_DIR_ . '/.well-known';
                    if (!is_dir($wellKnownDir)) {
                        @mkdir($wellKnownDir, 0755, true);
                    }
                    @copy($sourceFile, $destinationFile);
                }
            }
        }

        // 11. Clean up deprecated configurations
        $deprecatedConfigs = [
            'MONEI_CART_TO_ORDER',
            'MONEI_SHOW_PAYMENT_LOGOS',  // Old config name
            'MONEI_GATEWAY_TITLE',         // Old config name
        ];

        foreach ($deprecatedConfigs as $configKey) {
            Configuration::deleteByName($configKey);
        }

        // 12. Regenerate .htaccess and clear caches
        try {
            // Regenerate .htaccess
            if (method_exists('Tools', 'generateHtaccess')) {
                Tools::generateHtaccess();
            }

            // Clear caches
            Tools::clearSmartyCache();
            Tools::clearXMLCache();

            // Clear class cache
            if (file_exists(_PS_CACHE_DIR_ . 'class_index.php')) {
                unlink(_PS_CACHE_DIR_ . 'class_index.php');
            }

            // Clear Symfony cache if exists (PS 1.7+)
            $sfCacheDir = _PS_ROOT_DIR_ . '/var/cache';
            if (is_dir($sfCacheDir)) {
                Tools::deleteDirectory($sfCacheDir, false);
            }
        } catch (Exception $e) {
            // Cache clearing is not critical
            Monei::logWarning('[MONEI] Upgrade 1.7.5 - Cache clearing warning: ' . $e->getMessage());
        }

        return true;
    } catch (Exception $e) {
        Monei::logError('[MONEI] Upgrade 1.7.5 failed: ' . $e->getMessage());

        return false;
    }
}
