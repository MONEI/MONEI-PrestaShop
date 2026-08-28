<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.8.0 - express checkout, split card fields, automatic capture.
 *
 * ⚠️ `registerHook()` in `install()` runs only on a fresh install, and
 * `Configuration::updateValue` in `install()` likewise. Without this script an
 * upgrading merchant gets none of the new hooks and none of the new defaults:
 * express checkout would never render, automatic capture would never fire, and
 * `MONEI_CARD_LAYOUT` would read empty so the split card default would silently
 * not apply.
 */
function upgrade_module_1_8_0($module)
{
    try {
        // Only seeds values that are absent. A merchant who already changed one
        // of these must not have it reset by an upgrade.
        $defaults = [
            'MONEI_CARD_LAYOUT' => 'split',
            'MONEI_EXPRESS_ENABLED' => false,
            'MONEI_EXPRESS_LOCATIONS' => 'product,cart,checkout',
            'MONEI_EXPRESS_METHODS' => 'applePay,googlePay,paypal',
            'MONEI_CAPTURE_STATUS' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) === false) {
                Configuration::updateValue($key, $value);
            }
        }

        $hooks = [
            'actionOrderStatusPostUpdate',
            'displayProductAdditionalInfo',
            'displayExpressCheckout',
            'displayPaymentTop',
        ];

        foreach ($hooks as $hook) {
            if (!$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
                Monei::logError('[MONEI] Upgrade to 1.8.0 could not register hook ' . $hook);

                return false;
            }
        }

        Monei::logDebug('[MONEI] Upgrade to 1.8.0 completed successfully');

        return true;
    } catch (Exception $e) {
        Monei::logError('[MONEI] Upgrade to 1.8.0 failed: ' . $e->getMessage());

        return false;
    }
}
