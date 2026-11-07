<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.2.2 - make shoppers load the new front-office scripts.
 *
 * With "Smart cache for JavaScript" on, the theme bundle's URL names the bundled
 * file paths, not their contents, and is served with a long max-age. Without a
 * cache clear, browsers keep running the previous release's scripts.
 */
function upgrade_module_2_2_2($module)
{
    try {
        // Bumps PS_CCCJS_VERSION and PS_CCCCSS_VERSION, so the bundles get new URLs.
        Media::clearCache();

        Monei::logDebug('[MONEI] Upgrade to 2.2.2 completed successfully');

        return true;
    } catch (Exception $e) {
        Monei::logError('[MONEI] Upgrade to 2.2.2 failed: ' . $e->getMessage());

        return false;
    }
}
