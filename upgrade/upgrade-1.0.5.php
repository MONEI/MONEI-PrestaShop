<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_5($module)
{
    Configuration::updateValue('MONEI_EXPIRE_TIME', 600);
    return true;
}
