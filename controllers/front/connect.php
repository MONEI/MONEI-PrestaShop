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
    /**
     * The broker's redirect must reach postProcess() in maintenance mode too, or the
     * code expires behind the maintenance page. The state is the gate.
     */
    protected function displayMaintenancePage()
    {
    }

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
            $result = ['switched' => [], 'problems' => [['mode' => null, 'code' => 'temporarily_unavailable']], 'scope' => null, 'settingsUrl' => ''];
        }

        if ($result['switched']) {
            $module->afterConnectSwitch($result['switched'], $result['scope']);
        }

        $messages = [];
        $lines = [];
        if ($result['switched']) {
            $messages[] = $module->l('MONEI connected.', 'connect');
        }
        foreach (array_keys($result['switched']) as $mode) {
            $lines[] = ['ok' => true, 'text' => sprintf($module->l('%s: connected.', 'connect'), $mode === 'live' ? $module->l('Live', 'connect') : $module->l('Test', 'connect'))];
        }
        foreach ($result['problems'] as $problem) {
            $message = $module->connectProblemMessage($problem['mode'], $problem['code']);
            $messages[] = $message;
            $lines[] = ['ok' => false, 'text' => $message];
        }

        // Without a valid state nothing names a shop, so nothing is stored for one.
        $refused = $result['scope'] === null;
        if (!$refused) {
            $module->storeConnectMessages($messages, $result['scope']);
        }

        if ($result['switched']) {
            $title = $module->l('MONEI is connected', 'connect');
        } elseif ($refused) {
            $title = $module->l('This connect link does not work', 'connect');
        } else {
            $title = $module->l('MONEI is not connected', 'connect');
        }

        $this->context->smarty->assign([
            'monei_connect_result' => [
                'state' => $result['switched'] ? 'connected' : ($refused ? 'refused' : 'failed'),
                'title' => $title,
                'lines' => $lines,
                'settingsUrl' => $result['settingsUrl'],
                'logoUrl' => $module->getPathUri() . 'views/img/monei-logo.svg',
                'lang' => (string) $this->context->language->iso_code,
            ],
        ]);

        http_response_code($refused ? 400 : 200);
        header('Content-Type: text/html; charset=utf-8');
        // A whole page, not the shop theme: nothing on it loads from another host.
        echo $this->context->smarty->fetch($module->getLocalPath() . 'views/templates/front/connect_result.tpl');
        exit;
    }
}
