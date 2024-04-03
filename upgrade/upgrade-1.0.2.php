<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    Configuration::updateValue('MONEI_ACCOUNT_ID', '');

    return true;
}
