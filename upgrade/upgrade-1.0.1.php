<?php


if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $module->registerHook('displayCustomerAccount');
    // GDPR Compliance
    $module->registerHook('registerGDPRConsent');
    $module->registerHook('actionDeleteGDPRCustomer');
    $module->registerHook('actionExportGDPRData');

    $sql = [];
    $sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'monei_tokens`';
    $sql[] = 'CREATE TABLE `' . _DB_PREFIX_ . 'monei_tokens` (
        `id_monei_tokens` int(11) NOT NULL AUTO_INCREMENT,
        `id_customer` int(11) NOT NULL,
        `brand` varchar(50) DEFAULT NULL,
        `country` varchar(4) DEFAULT NULL,
        `last_four` varchar(20) NOT NULL,
        `threeDS` tinyint(1) DEFAULT NULL,
        `threeDS_version` varchar(50) DEFAULT NULL,
        `expiration` int(11) NOT NULL,
        `tokenized` varchar(255) NOT NULL,
        `date_add` datetime DEFAULT NULL,
        PRIMARY KEY (`id_monei_tokens`),
        INDEX (`id_customer`)
      ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

    foreach ($sql as $query) {
        Db::getInstance()->execute($query);
    }

    return true;
}
