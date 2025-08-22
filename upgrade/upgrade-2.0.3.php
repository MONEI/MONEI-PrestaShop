<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_2_0_3($module)
{
    // Add PayPal redirect mode configuration
    Configuration::updateValue('MONEI_PAYPAL_WITH_REDIRECT', false);

    return true;
}