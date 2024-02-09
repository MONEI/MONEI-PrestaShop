<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_0($module)
{
    Configuration::updateValue('MONEI_ALLOW_KLARNA', false);
    Configuration::updateValue('MONEI_ALLOW_MULTIBANCO', false);
    Configuration::updateValue('MONEI_SHOW_ALL', true);

    return true;
}
