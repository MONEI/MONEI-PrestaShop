<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_5_2()
{
    $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'monei` DROP `locked_at`;';
    Db::getInstance()->execute($sql);

    $sql = 'ALTER TABLE `' . _DB_PREFIX_ . 'monei` DROP `locked`;';
    Db::getInstance()->execute($sql);

    return true;
}
