<?php
$sql = [];

// Drop ALL module tables on uninstall (GDPR compliant - data minimization)
// This ensures complete removal of all customer data when module is uninstalled
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_payment`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_history`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_refund`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_customer_card`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_order_payment`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
