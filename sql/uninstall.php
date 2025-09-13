<?php
$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_payment`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_history`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_refund`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_customer_card`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_order_payment`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_admin_order_message`;';

// Clean up all MONEI configuration entries
$sql[] = 'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "MONEI_%";';
$sql[] = 'DELETE FROM `' . _DB_PREFIX_ . 'configuration_lang` WHERE `id_configuration` IN (SELECT `id_configuration` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE "MONEI_%");';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
