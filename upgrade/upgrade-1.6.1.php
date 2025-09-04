<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_1()
{
    // Add new configuration for PayPal redirect mode if not exists
    if (!Configuration::hasKey('MONEI_PAYPAL_WITH_REDIRECT')) {
        Configuration::updateValue('MONEI_PAYPAL_WITH_REDIRECT', false);
    }

    return true;
}
