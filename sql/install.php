<?php
// Determine charset based on PrestaShop version
// PS 1.7.7+ uses utf8mb4 for emoji support
$charset = version_compare(_PS_VERSION_, '1.7.7.0', '>=')
    ? 'utf8mb4 COLLATE=utf8mb4_unicode_ci'
    : 'utf8';

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_payment` (
    `id_payment` VARCHAR(50) NOT NULL,
    `id_cart` INT(11) NOT NULL,
    `id_order` INT(11) DEFAULT NULL,
    `id_order_monei` VARCHAR(50) DEFAULT NULL,
    `amount` INT(11) NOT NULL,
    `refunded_amount` INT(11) DEFAULT NULL,
    `currency` VARCHAR(3) DEFAULT NULL,
    `authorization_code` VARCHAR(50) DEFAULT NULL,
    `status` ENUM("PENDING", "SUCCEEDED", "FAILED", "CANCELED", "REFUNDED", "PARTIALLY_REFUNDED", "AUTHORIZED", "EXPIRED", "UNKNOWN") DEFAULT "PENDING",
    `is_captured` TINYINT(1) DEFAULT 0,
    `status_code` VARCHAR(10) DEFAULT NULL,
    `date_add` DATETIME,
    `date_upd` DATETIME,
    PRIMARY KEY (`id_payment`),
    INDEX (`id_cart`),
    INDEX (`id_order`),
    INDEX (`id_order_monei`),
    INDEX (`id_cart`, `id_order_monei`),
    INDEX (`id_order`, `id_order_monei`),
    INDEX (`id_cart`, `id_order`, `id_order_monei`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_history` (
    `id_history` INT(11) NOT NULL AUTO_INCREMENT,
    `id_payment` VARCHAR(50) NOT NULL,
    `status` ENUM("PENDING", "SUCCEEDED", "FAILED", "CANCELED", "REFUNDED", "PARTIALLY_REFUNDED", "AUTHORIZED", "EXPIRED", "UNKNOWN") DEFAULT "PENDING",
    `status_code` VARCHAR(4) DEFAULT NULL,
    `response` VARCHAR(4000) DEFAULT NULL,
    `date_add` DATETIME,
    PRIMARY KEY (`id_history`),
    INDEX (`id_payment`),
    INDEX (`status_code`),
    INDEX (`id_payment`, `status_code`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_customer_card` (
    `id_customer_card` INT(11) NOT NULL AUTO_INCREMENT,
    `id_customer` INT(11) NOT NULL,
    `brand` VARCHAR(50) DEFAULT NULL,
    `country` VARCHAR(4) DEFAULT NULL,
    `last_four` VARCHAR(20) NOT NULL,
    `expiration` INT(11) NOT NULL,
    `tokenized` VARCHAR(255) NOT NULL,
    `date_add` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id_customer_card`),
    INDEX (`id_customer`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_refund` (
    `id_refund` INT(11) NOT NULL AUTO_INCREMENT,
    `id_payment` VARCHAR(50) NOT NULL,
    `id_history` INT(11) DEFAULT NULL,
    `id_employee` INT(11) DEFAULT NULL,
    `reason` ENUM("duplicated", "fraudulent", "requested_by_customer") DEFAULT "requested_by_customer",
    `amount` INT(11) DEFAULT NULL,
    `date_add` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id_refund`),
    INDEX (`id_payment`),
    INDEX (`id_history`),
    INDEX (`id_employee`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_order_payment` (
    `id_payment` VARCHAR(50) NOT NULL,
    `id_order` INT(11) NOT NULL,
    `date_add` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id_payment`),
    INDEX (`id_order`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'monei2_admin_order_message` (
    `id_monei_admin_order_message` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `id_order` INT(10) UNSIGNED NOT NULL,
    `message` TEXT,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_monei_admin_order_message`),
    INDEX (`id_order`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=' . $charset . ';';

// Execute SQL queries
foreach ($sql as $query) {
    if (Db::getInstance()->execute($query) === false) {
        return false;
    }
}
