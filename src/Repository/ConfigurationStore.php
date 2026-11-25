<?php

namespace PsMonei\Repository;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * ps_configuration for ConnectService and CallbackVerifier.
 *
 * Shop values go through Configuration with an explicit scope, so the callback
 * front controller writes the shop that started the connect, not its own.
 *
 * The rows of no shop (the connect states) go through Db with the query cache off:
 * a state is single-use only if the delete that removed it is the one that counts,
 * and Configuration's cache is filled once per request.
 */
class ConfigurationStore implements ConfigurationStoreInterface
{
    public function get(string $key, int $idShopGroup, int $idShop): string
    {
        return (string) \Configuration::get($key, null, $idShopGroup, $idShop);
    }

    public function set(string $key, string $value, int $idShopGroup, int $idShop): void
    {
        \Configuration::updateValue($key, $value, false, $idShopGroup, $idShop);
    }

    public function getGlobal(string $key): ?string
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\''
            . ' AND `id_shop_group` IS NULL AND `id_shop` IS NULL LIMIT 1',
            true,
            false
        );

        return is_array($rows) && $rows ? (string) $rows[0]['value'] : null;
    }

    public function setGlobal(string $key, string $value): void
    {
        \Configuration::updateGlobalValue($key, $value);
    }

    public function deleteGlobal(string $key): bool
    {
        $db = \Db::getInstance();
        $db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\''
            . ' AND `id_shop_group` IS NULL AND `id_shop` IS NULL'
        );

        return $db->Affected_Rows() > 0;
    }

    public function globalNamesWithPrefix(string $prefix): array
    {
        $rows = \Db::getInstance()->executeS(
            'SELECT `name` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` LIKE \'' . pSQL(addcslashes($prefix, '%_\\')) . '%\''
            . ' AND `id_shop_group` IS NULL AND `id_shop` IS NULL',
            true,
            false
        );

        return is_array($rows) ? array_column($rows, 'name') : [];
    }
}
