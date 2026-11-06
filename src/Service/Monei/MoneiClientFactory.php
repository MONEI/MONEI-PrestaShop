<?php

namespace PsMonei\Service\Monei;

use Monei\MoneiClient;

/**
 * The only place that builds a MONEI client.
 *
 * ⚠️ `new MoneiClient($key)` without a configuration writes the key into the
 * SDK's shared default Configuration, so every client built earlier in the same
 * request then sends that key too. A request that holds test and live clients at
 * once (a customer delete) would send calls with the wrong key. Each client here
 * gets its own Configuration.
 *
 * Pure on purpose: no PrestaShop classes, so the unit suite can load it. The
 * caller passes the user agent, because MoneiService::getUserAgent() reads
 * PrestaShop constants.
 */
final class MoneiClientFactory
{
    public static function create(string $apiKey, string $userAgent): MoneiClient
    {
        $client = new MoneiClient($apiKey, new \Monei\Configuration());
        $client->setUserAgent($userAgent);

        return $client;
    }
}
