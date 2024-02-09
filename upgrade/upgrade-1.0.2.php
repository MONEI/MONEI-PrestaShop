<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_2($module)
{
    $sql = [];
    $sql[] = 'ALTER TABLE `' . _DB_PREFIX_ . 'monei_history` ADD COLUMN is_callback BOOLEAN';
    $sql[] = 'ALTER TABLE `' . _DB_PREFIX_ . 'monei_history` ADD COLUMN is_refund BOOLEAN';

    foreach ($sql as $query) {
        Db::getInstance()->execute($query);
    }

    return true;
}
