<?php
$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mo_payment`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mo_history`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mo_refund`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mo_token`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
