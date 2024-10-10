<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_7($module)
{
    $module->registerHook('actionCustomerLogoutAfter');

    return true;
}
