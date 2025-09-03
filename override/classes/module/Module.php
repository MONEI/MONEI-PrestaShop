<?php

class Module extends ModuleCore
{
    /**
     * Override to ensure payment module properties are properly loaded
     * Fixes issue where currencies_mode is not recognized in PrestaShop 1.7.2
     */
    public static function getModulesOnDisk($useConfig = false, $loggedOnAddons = false, $idEmployee = false)
    {
        $modules = parent::getModulesOnDisk($useConfig, $loggedOnAddons, $idEmployee);
        
        // Fix payment modules to ensure their properties are loaded
        foreach ($modules as &$module) {
            if (isset($module->tab) && $module->tab == 'payments_gateways') {
                // Try to get the actual module instance to load properties
                try {
                    if (file_exists(_PS_MODULE_DIR_ . $module->name . '/' . $module->name . '.php')) {
                        require_once(_PS_MODULE_DIR_ . $module->name . '/' . $module->name . '.php');
                        $className = $module->name;
                        if (class_exists($className)) {
                            $instance = new $className();
                            
                            // Copy payment-specific properties if they exist
                            if (isset($instance->currencies)) {
                                $module->currencies = $instance->currencies;
                            }
                            if (isset($instance->currencies_mode)) {
                                $module->currencies_mode = $instance->currencies_mode;
                            }
                            if (isset($instance->limited_countries)) {
                                $module->limited_countries = $instance->limited_countries;
                            }
                            if (isset($instance->limited_currencies)) {
                                $module->limited_currencies = $instance->limited_currencies;
                            }
                        }
                    }
                } catch (Exception $e) {
                    // Silently continue if module can't be loaded
                }
            }
        }
        
        return $modules;
    }
}