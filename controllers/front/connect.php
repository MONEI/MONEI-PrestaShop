<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * The callback of "Connect with MONEI": the broker sends the merchant back here
 * with code and state.
 *
 * A public route, because a back-office URL carries a token that must never become
 * a redirect_uri. The single-use state is the only gate. It is enough because only
 * the admin start route (getContent) stores a state.
 */
class MoneiConnectModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // The code is in this URL: it must never leave in a Referer.
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');

        /** @var Monei $module */
        $module = $this->module;

        try {
            $result = $module->connectService()->complete((string) Tools::getValue('state'), (string) Tools::getValue('code'));
        } catch (Throwable $e) {
            Monei::logError('[MONEI] Connect failed: ' . get_class($e));
            $result = ['switched' => [], 'problems' => [['mode' => null, 'code' => 'temporarily_unavailable']], 'scope' => null];
        }

        if ($result['switched']) {
            $module->afterConnectSwitch($result['switched'], $result['scope']);
        }

        $messages = [];
        if ($result['switched']) {
            $messages[] = $module->l('MONEI connected.', 'connect');
        }
        foreach ($result['problems'] as $problem) {
            $messages[] = $module->connectProblemMessage($problem['mode'], $problem['code']);
        }

        // Without a valid state nothing names a shop, so nothing is stored for one.
        $refused = $result['scope'] === null;
        if (!$refused) {
            $module->storeConnectMessages($messages, $result['scope']);
            $messages[] = $module->l('You can close this tab.', 'connect');
        }

        http_response_code($refused ? 400 : 200);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>' . htmlspecialchars($module->l('Connect with MONEI', 'connect'), ENT_QUOTES, 'UTF-8') . '</title></head><body>';
        foreach ($messages as $message) {
            echo '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        echo '</body></html>';
        exit;
    }
}
