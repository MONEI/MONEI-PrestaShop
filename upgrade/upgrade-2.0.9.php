<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to version 2.0.9
 * Comprehensive upgrade that:
 * - Ensures all database tables exist
 * - Re-registers all hooks
 * - Recreates admin tabs if missing
 * - Adds missing configuration values
 * - Validates order states
 * This helps fix issues when upgrading from older versions or recovering from problems
 */
function upgrade_module_2_0_9($module)
{
    $success = true;
    $db = Db::getInstance();
    
    PrestaShopLogger::addLog(
        '[MONEI] Starting comprehensive upgrade to version 2.0.9',
        PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
    );
    
    // 1. ENSURE ALL DATABASE TABLES EXIST
    // Check and recreate tables if they don't exist
    $requiredTables = [
        'monei2_payment',
        'monei2_history',
        'monei2_refund',
        'monei2_customer_card',
        'monei2_order_payment'
    ];
    
    $missingTables = [];
    foreach ($requiredTables as $table) {
        $sql = "SHOW TABLES LIKE '" . _DB_PREFIX_ . $table . "'";
        if (!$db->executeS($sql)) {
            $missingTables[] = $table;
        }
    }
    
    if (!empty($missingTables)) {
        PrestaShopLogger::addLog(
            '[MONEI] Missing tables detected: ' . implode(', ', $missingTables) . '. Recreating...',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
        );
        
        // Run install.php to recreate missing tables
        include dirname(__FILE__) . '/../sql/install.php';
        
        PrestaShopLogger::addLog(
            '[MONEI] Database tables recreated',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    }
    
    // 2. RE-REGISTER ALL HOOKS
    $requiredHooks = [
        'actionFrontControllerSetMedia',
        'displayCustomerAccount',
        'actionDeleteGDPRCustomer',
        'actionExportGDPRData',
        'displayBackOfficeHeader',
        'displayAdminOrder',
        'displayPaymentByBinaries',
        'paymentOptions',
        'displayPaymentReturn',
        'actionCustomerLogoutAfter',
        'moduleRoutes',
        'actionOrderSlipAdd',
        'actionGetAdminOrderButtons'
    ];
    
    $hooksRegistered = 0;
    foreach ($requiredHooks as $hookName) {
        if (!$module->isRegisteredInHook($hookName)) {
            if ($module->registerHook($hookName)) {
                $hooksRegistered++;
                PrestaShopLogger::addLog(
                    '[MONEI] Registered missing hook: ' . $hookName,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
            } else {
                $success = false;
                PrestaShopLogger::addLog(
                    '[MONEI] Failed to register hook: ' . $hookName,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
            }
        }
    }
    
    if ($hooksRegistered > 0) {
        PrestaShopLogger::addLog(
            '[MONEI] Registered ' . $hooksRegistered . ' missing hooks',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    }
    
    // 3. ENSURE ADMIN TABS EXIST
    $adminTabs = [
        'AdminMonei' => 'MONEI',
        'AdminMoneiCapturePayment' => 'MONEI Capture Payment'
    ];
    
    foreach ($adminTabs as $className => $tabName) {
        $tabId = Tab::getIdFromClassName($className);
        if (!$tabId) {
            $tab = new Tab();
            $tab->active = 1;
            $tab->class_name = $className;
            $tab->name = [];
            
            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = $tabName;
            }
            
            $tab->id_parent = -1;
            $tab->module = $module->name;
            
            if ($tab->add()) {
                PrestaShopLogger::addLog(
                    '[MONEI] Recreated admin tab: ' . $className,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
            } else {
                $success = false;
                PrestaShopLogger::addLog(
                    '[MONEI] Failed to create admin tab: ' . $className,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
            }
        }
    }
    
    // 4. ADD MISSING CONFIGURATION VALUES
    // These might be missing in older versions
    $defaultConfigurations = [
        'MONEI_TOKENIZE' => false,
        'MONEI_PRODUCTION_MODE' => false,
        'MONEI_SHOW_LOGO' => true,
        'MONEI_EXPIRE_TIME' => 600,
        'MONEI_ALLOW_CARD' => true,
        'MONEI_CARD_WITH_REDIRECT' => false,
        'MONEI_ALLOW_BIZUM' => false,
        'MONEI_BIZUM_WITH_REDIRECT' => false,
        'MONEI_ALLOW_APPLE' => false,
        'MONEI_ALLOW_GOOGLE' => false,
        'MONEI_ALLOW_PAYPAL' => false,
        'MONEI_PAYPAL_WITH_REDIRECT' => false,
        'MONEI_ALLOW_MULTIBANCO' => false,
        'MONEI_ALLOW_MBWAY' => false,
        'MONEI_PAYMENT_ACTION' => 'sale',
        'MONEI_SWITCH_REFUNDS' => true,
        'MONEI_CARD_INPUT_STYLE' => '{"base": {"height": "42px"}, "input": {"background": "none"}}',
        'MONEI_BIZUM_STYLE' => '{"height": "42"}',
        'MONEI_PAYMENT_REQUEST_STYLE' => '{"height": "42"}',
        'MONEI_PAYPAL_STYLE' => '{"height": "42"}'
    ];
    
    foreach ($defaultConfigurations as $key => $defaultValue) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $defaultValue);
            PrestaShopLogger::addLog(
                '[MONEI] Added missing configuration: ' . $key,
                PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );
        }
    }
    
    // Set order status configurations if not set
    if (!Configuration::get('MONEI_STATUS_SUCCEEDED')) {
        Configuration::updateValue('MONEI_STATUS_SUCCEEDED', Configuration::get('PS_OS_PAYMENT'));
    }
    if (!Configuration::get('MONEI_STATUS_FAILED')) {
        Configuration::updateValue('MONEI_STATUS_FAILED', Configuration::get('PS_OS_ERROR'));
    }
    if (!Configuration::get('MONEI_STATUS_REFUNDED')) {
        Configuration::updateValue('MONEI_STATUS_REFUNDED', Configuration::get('PS_OS_REFUND'));
    }
    if (!Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED')) {
        Configuration::updateValue('MONEI_STATUS_PARTIALLY_REFUNDED', Configuration::get('PS_OS_REFUND'));
    }
    if (!Configuration::get('MONEI_STATUS_PENDING')) {
        Configuration::updateValue('MONEI_STATUS_PENDING', Configuration::get('PS_OS_PREPARATION'));
    }
    
    // 5. VALIDATE ORDER STATES
    if (method_exists($module, 'validateOrderStates')) {
        $module->validateOrderStates();
        PrestaShopLogger::addLog(
            '[MONEI] Order states validated',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    }
    
    // 6. ENSURE APPLE PAY DOMAIN VERIFICATION FILE
    if (is_callable([$module, 'copyApplePayDomainVerificationFile'])) {
        $module->copyApplePayDomainVerificationFile();
        PrestaShopLogger::addLog(
            '[MONEI] Apple Pay domain verification file checked',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    }
    
    // 7. CLEAN UP DEPRECATED CONFIGURATIONS
    Configuration::deleteByName('MONEI_CART_TO_ORDER');
    Configuration::deleteByName('MONEI_ALLOW_COFIDIS');
    
    // 8. REGENERATE .HTACCESS
    if (class_exists('Tools') && method_exists('Tools', 'generateHtaccess')) {
        Tools::generateHtaccess();
        PrestaShopLogger::addLog(
            '[MONEI] .htaccess regenerated',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    }
    
    // 9. CLEAR CACHE
    if (method_exists('Tools', 'clearSmartyCache')) {
        Tools::clearSmartyCache();
    }
    
    // Clear module cache if using Symfony cache
    if (method_exists($module, 'getCacheClearerChain')) {
        try {
            $cacheClearer = $module->getCacheClearerChain();
            if ($cacheClearer) {
                $cacheClearer->clear();
            }
        } catch (Exception $e) {
            // Cache clearing is not critical
        }
    }
    
    if ($success) {
        PrestaShopLogger::addLog(
            '[MONEI] Successfully completed comprehensive upgrade to version 2.0.9',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );
    } else {
        PrestaShopLogger::addLog(
            '[MONEI] Upgrade to version 2.0.9 completed with some errors - please check logs',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
        );
    }
    
    return $success;
}