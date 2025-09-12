<?php
$sql = [];

// Only drop transactional/temporary data tables
// Keep customer data (monei2_customer_card) to preserve tokenized cards
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_payment`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_history`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_refund`;';
// IMPORTANT: Do NOT drop monei2_customer_card - contains customer payment methods
// $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_customer_card`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei2_order_payment`;';

foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
