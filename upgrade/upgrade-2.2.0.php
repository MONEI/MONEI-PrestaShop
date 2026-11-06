<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.2.0 - link payments to MONEI customers.
 *
 * Creates monei2_customer and attaches the hooks that delete a MONEI customer
 * together with the PrestaShop one.
 */
function upgrade_module_2_2_0($module)
{
    try {
        // The one definition of every table; each statement is CREATE TABLE IF NOT
        // EXISTS, so the existing tables are left alone. It answers false on failure.
        if ((include dirname(__FILE__) . '/../sql/install.php') === false) {
            Monei::logError('[MONEI] Upgrade to 2.2.0 could not create monei2_customer');

            return false;
        }

        $db = Db::getInstance();

        // ⚠️ Hook::registerHook() deletes the module's module_shop row on every shop
        // in its list that is inactive or hidden from the employee running the
        // upgrade, even with an explicit shop list, which turns MONEI off there.
        // Keep the rows as they are now and put back any that it removes.
        $moduleShops = $db->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'module_shop` WHERE `id_module` = ' . (int) $module->id,
            true,
            false
        );
        if (!is_array($moduleShops)) {
            Monei::logError('[MONEI] Upgrade to 2.2.0 could not read the module shops');

            return false;
        }

        // Every shop, not Shop::getShops(), which hides shops from a restricted employee.
        $shopIds = array_map('intval', Shop::getCompleteListOfShopsID());
        $hooks = ['actionObjectCustomerDeleteAfter', 'actionObjectCustomerUpdateAfter'];

        $missingOn = static function ($hook) use ($module, $shopIds) {
            return array_values(array_filter($shopIds, static function ($idShop) use ($module, $hook) {
                return !Hook::isModuleRegisteredOnHook($module, $hook, $idShop);
            }));
        };

        foreach ($hooks as $hook) {
            $missing = $missingOn($hook);
            if ($missing) {
                // The return value does not prove anything; the check below does.
                $module->registerHook($hook, $missing);
            }
        }

        foreach ($moduleShops as $row) {
            $db->insert('module_shop', $row, false, false, Db::INSERT_IGNORE);
        }

        foreach ($hooks as $hook) {
            if ($missingOn($hook)) {
                Monei::logError('[MONEI] Upgrade to 2.2.0 could not register hook ' . $hook);

                return false;
            }
        }

        Monei::logDebug('[MONEI] Upgrade to 2.2.0 completed successfully');

        return true;
    } catch (Exception $e) {
        Monei::logError('[MONEI] Upgrade to 2.2.0 failed: ' . $e->getMessage());

        return false;
    }
}
