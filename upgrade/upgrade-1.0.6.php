<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_6($module)
{
    Configuration::updateValue('MONEI_ALLOW_KLARNA', false);
    Configuration::updateValue('MONEI_ALLOW_MULTIBANCO', false);

    // Create the new fields for monei table
    $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'monei` ADD `locked` TINYINT(1) DEFAULT 0 AFTER `status`'
        . ', ADD `locked_at` INT(11) DEFAULT NULL AFTER `locked`';

    if (!Db::getInstance()->execute($sql)) {
        return false;
    }

    return true;
}
