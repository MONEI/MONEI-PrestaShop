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
                PrestaShopLogger::addLog(
                    '[MONEI] Upgrade 1.7.5 - Failed to create table: ' . $query,
                    3
                );

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

        // 6. Validate and create missing order states
        $orderStates = [
            'MONEI_STATUS_PENDING' => [
                'name' => 'Awaiting payment',
                'color' => '#8961A5',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ],
            'MONEI_STATUS_SUCCEEDED' => [
                'name' => 'Payment accepted',
                'color' => '#32CD32',
                'send_email' => true,
                'paid' => true,
                'invoice' => true,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ],
            'MONEI_STATUS_FAILED' => [
                'name' => 'Payment error',
                'color' => '#DC143C',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => false,
                'delivery' => false,
            ],
            'MONEI_STATUS_REFUNDED' => [
                'name' => 'Refunded',
                'color' => '#ec2e15',
                'send_email' => true,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => false,
                'delivery' => false,
            ],
            'MONEI_STATUS_PARTIALLY_REFUNDED' => [
                'name' => 'Partially refunded',
                'color' => '#FFA500',
                'send_email' => true,
                'paid' => true,
                'invoice' => true,
                'shipped' => false,
                'logable' => false,
                'delivery' => false,
            ],
            'MONEI_STATUS_AUTHORIZED' => [
                'name' => 'Authorized (not captured)',
                'color' => '#4169E1',
                'send_email' => false,
                'paid' => false,
                'invoice' => false,
                'shipped' => false,
                'logable' => true,
                'delivery' => false,
            ],
        ];

        foreach ($orderStates as $configKey => $stateData) {
            $stateId = Configuration::get($configKey);
            if (!$stateId) {
                // Create the order state
                $orderState = new OrderState();
                $orderState->name = [];
                foreach (Language::getLanguages() as $language) {
                    $orderState->name[$language['id_lang']] = $stateData['name'];
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
            }
        }

        // 7. Install Order override for reference synchronization
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
            PrestaShopLogger::addLog(
                '[MONEI] Upgrade 1.7.5 - Failed to install Order override: ' . $e->getMessage(),
                2
            );
            // Continue with upgrade even if override fails
        }

        // 8. Ensure Apple Pay domain verification file exists
        if (method_exists($module, 'copyApplePayDomainVerificationFile')) {
            try {
                // Use reflection to call the method even if it's private
                $reflection = new ReflectionMethod($module, 'copyApplePayDomainVerificationFile');
                if ($reflection->isPrivate() || $reflection->isProtected()) {
                    $reflection->setAccessible(true);
                }
                $reflection->invoke($module);
            } catch (Exception $e) {
                // If the method is not accessible, try directly
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

        // 9. Clean up deprecated configurations
        $deprecatedConfigs = [
            'MONEI_CART_TO_ORDER',
            'MONEI_SHOW_PAYMENT_LOGOS',  // Old config name
            'MONEI_GATEWAY_TITLE',         // Old config name
        ];

        foreach ($deprecatedConfigs as $configKey) {
            Configuration::deleteByName($configKey);
        }

        // 10. Regenerate .htaccess and clear caches
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
            PrestaShopLogger::addLog(
                '[MONEI] Upgrade 1.7.5 - Cache clearing warning: ' . $e->getMessage(),
                2
            );
        }

        PrestaShopLogger::addLog(
            '[MONEI] Successfully upgraded module to version 1.7.5',
            1
        );

        return true;
    } catch (Exception $e) {
        PrestaShopLogger::addLog(
            '[MONEI] Upgrade 1.7.5 failed: ' . $e->getMessage(),
            3
        );

        return false;
    }
}
