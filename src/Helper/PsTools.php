<?php
namespace PsMonei\Helper;

use Tools;

class PsTools
{
    /**
     * Detects if Safari is the current browser.
     * @return bool
     */
    public static function isSafariBrowser()
    {
        $userBrowser = Tools::getUserBrowser();
        if (strpos($userBrowser, 'Safari') !== false) {
            return true;
        }
        return false;
    }
}
