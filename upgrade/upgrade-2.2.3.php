<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Upgrade to 2.2.3 - make shoppers load the new front-office scripts.
 *
 * With "Smart cache for JavaScript" on, the theme bundle's URL names the bundled
 * file paths, not their contents, and is served with a long max-age. Without a
 * cache clear, browsers keep running the previous release's scripts.
 */
function upgrade_module_2_2_3($module)
{
    try {
        // Bumps PS_CCCJS_VERSION and PS_CCCCSS_VERSION, so the bundles get new URLs.
        Media::clearCache();

        Monei::logDebug('[MONEI] Upgrade to 2.2.3 completed successfully');

        return true;
    } catch (Throwable $e) {
        // A stale bundle is not worth a failed upgrade: under open_basedir the
        // cache clear can throw a TypeError, and the scripts still load on the next
        // cache clear the merchant runs.
        Monei::logError('[MONEI] Upgrade to 2.2.3 could not clear the theme cache: ' . $e->getMessage());

        return true;
    }
}
