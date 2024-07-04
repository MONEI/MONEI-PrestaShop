<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_7($module)
{
    Configuration::updateValue('MONEI_CARD_WITH_REDIRECT', false);
    Configuration::updateValue('MONEI_BIZUM_WITH_REDIRECT', false);

    $module->registerHook('displayPaymentByBinaries');

    return true;
}
