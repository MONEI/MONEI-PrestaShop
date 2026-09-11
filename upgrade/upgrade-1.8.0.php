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
 * `MONEI_CARD_LAYOUT` would read empty.
 */
function upgrade_module_1_8_0($module)
{
    try {
        // Only seeds values that are absent. A merchant who already changed one
        // of these must not have it reset by an upgrade.
        $defaults = [
            'MONEI_CARD_LAYOUT' => 'single',
            'MONEI_EXPRESS_ENABLED' => false,
            'MONEI_EXPRESS_LOCATIONS' => 'product,cart,checkout',
            'MONEI_EXPRESS_METHODS' => 'applePay,googlePay,paypal',
            'MONEI_CAPTURE_STATUS' => '',
        ];

        foreach ($defaults as $key => $value) {
            if (Configuration::get($key) !== false) {
                continue;
            }

            if (!Configuration::updateValue($key, $value)) {
                Monei::logError('[MONEI] Upgrade to 1.8.0 could not seed ' . $key);

                return false;
            }
        }

        $hooks = [
            'actionOrderStatusPostUpdate',
            'displayProductAdditionalInfo',
            'displayExpressCheckout',
            'displayPaymentTop',
        ];

        // ⚠️ Checked on every shop, not through Module::isRegisteredInHook(),
        // which only looks at the context shop. registerHook() with no shop list
        // registers everywhere, so one shop already carrying the hook would pass
        // a single-shop check while another still lacked it.
        $registeredOnEveryShop = static function (Monei $module, $hook) {
            foreach (Shop::getShops(true, null, true) as $idShop) {
                if (!Hook::isModuleRegisteredOnHook($module, $hook, (int) $idShop)) {
                    return false;
                }
            }

            return true;
        };

        foreach ($hooks as $hook) {
            if ($registeredOnEveryShop($module, $hook)) {
                continue;
            }

            $module->registerHook($hook);

            // ⚠️ Judge the outcome, not registerHook()'s return value. On 1.7 it
            // answers false for a hook that is already attached, so trusting it
            // aborts the upgrade over a no-op and leaves the merchant on the old
            // version with the rest of this script unapplied.
            if (!$registeredOnEveryShop($module, $hook)) {
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
