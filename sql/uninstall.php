<?php


$sql = [];

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei_history`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei_refund`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei_tokens`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei_codes`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
