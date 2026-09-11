<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 1.8.1 - take express checkout off the payment step.
 *
 * Express now renders on the product and cart pages only. By the checkout payment
 * step the shopper has already entered email, address and carrier, so the wallet
 * buttons there only duplicated the ordinary payment options and, through
 * requestShipping, could overwrite the address the shopper had just entered.
 */
function upgrade_module_1_8_1($module)
{
    try {
        // Drop 'checkout' from the locations, but only where it is still the
        // shipped 1.8.0 default. A merchant who chose their own set keeps it.
        if (Configuration::get('MONEI_EXPRESS_LOCATIONS') === 'product,cart,checkout') {
            if (!Configuration::updateValue('MONEI_EXPRESS_LOCATIONS', 'product,cart')) {
                Monei::logError('[MONEI] Upgrade to 1.8.1 could not update MONEI_EXPRESS_LOCATIONS');

                return false;
            }
        }

        // The payment-step express hook is gone from the module. Unregister it so
        // PrestaShop stops calling a hook that no longer has a handler.
        if (Hook::getIdByName('displayPaymentTop') && $module->isRegisteredInHook('displayPaymentTop')) {
            $module->unregisterHook('displayPaymentTop');
        }

        Monei::logDebug('[MONEI] Upgrade to 1.8.1 completed successfully');

        return true;
    } catch (Exception $e) {
        Monei::logError('[MONEI] Upgrade to 1.8.1 failed: ' . $e->getMessage());

        return false;
    }
}
