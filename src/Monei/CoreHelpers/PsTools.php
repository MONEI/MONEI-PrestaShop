<?php
namespace Monei\CoreHelpers;

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

        // $user_agent = $_SERVER['HTTP_USER_AGENT'];
        // $browser = new \foroco\BrowserDetection();
        // $browser_info = $browser->getBrowser($user_agent);
        // $os_info = $browser->getOS($user_agent);

        // if ((array_key_exists('os_family', $os_info) && $os_info['os_family'] == 'macintosh') &&
        //     (array_key_exists('browser_name', $browser_info) &&
        //         strpos($browser_info['browser_name'], 'Safari') !== false)) {
        //     return true;
        // }
        // return false;
    }
}
