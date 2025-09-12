<?php
use Monei\Model\PaymentStatus;
use PsMonei\Exception\MoneiException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        $transactionId = Tools::getValue('transaction_id');
        $tokenizeCard = (bool) Tools::getValue('tokenize_card', false);
        $moneiCardId = (int) Tools::getValue('id_monei_card', 0);
        $paymentMethod = Tools::getValue('method', '');

        $cart = $this->context->cart;

        // Validate cart before accessing properties
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog(
                '[MONEI] Invalid or missing cart in redirect controller',
                Monei::getLogLevel('error')
            );
            Tools::redirect($this->context->link->getPageLink('index'));
            exit;
        }

        PrestaShopLogger::addLog(
            '[MONEI] Redirect initiated [cart_id=' . $cart->id . ']',
            Monei::getLogLevel('info')
        );

        // Use PS1.7 compatible encryption
        $expected_hash = Tools::encrypt((int) $cart->id . (int) $cart->id_customer);
        $check_encrypt = ($expected_hash === $transactionId);

        if ($cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
            || !$this->module->active
        ) {
            Tools::redirect($this->context->link->getPageLink('index'));
            exit;
        }

        try {
            if (!$check_encrypt) {
                throw new MoneiException('Invalid crypto hash', MoneiException::INVALID_CRYPTO_HASH);
            }

            $moneiService = Monei::getService('service.monei');

            $moneiPayment = $moneiService->createMoneiPayment($cart, $tokenizeCard, $moneiCardId, $paymentMethod);
            if (!$moneiPayment) {
                Tools::redirect($this->context->link->getPageLink('order'));
                exit;
            }

            // Note: Cart to Order feature has been removed
            // Orders are now created only after successful payment confirmation

            $nextAction = $moneiPayment->getNextAction();
            if ($nextAction && $nextAction->getRedirectUrl()) {
                $redirectURL = $nextAction->getRedirectUrl();
                if ($moneiPayment->getStatus() === PaymentStatus::FAILED) {
                    // Store status code for failed payments before redirect
                    if ($moneiPayment->getStatusCode()) {
                        $this->context->cookie->monei_error_code = $moneiPayment->getStatusCode();
                    }
                    $redirectURL = $this->addQueryParam($redirectURL, 'message', $moneiPayment->getStatusMessage());
                }

                Tools::redirect($redirectURL);
                exit;
            }

            // If no redirect URL, go to order page
            Tools::redirect($this->context->link->getPageLink('order'));
            exit;
        } catch (Exception $ex) {
            // Store the exception message for technical errors
            $this->context->cookie->monei_error = $ex->getMessage();

            // If it's a MoneiException with a payment response, try to extract status code
            if ($ex instanceof MoneiException && method_exists($ex, 'getPaymentData')) {
                $paymentData = $ex->getPaymentData();
                if ($paymentData && isset($paymentData['statusCode'])) {
                    $this->context->cookie->monei_error_code = $paymentData['statusCode'];
                }
            }

            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors'));
        }

        exit;
    }

    /**
     * Add query parameter to URL safely
     *
     * @param string $url The URL to add parameter to
     * @param string $key The parameter key
     * @param string $value The parameter value
     *
     * @return string Modified URL
     */
    private function addQueryParam($url, $key, $value)
    {
        if (empty($value)) {
            return $url;
        }

        $parsed = parse_url($url);
        $query = [];

        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }

        $query[$key] = $value;

        $parsed['query'] = http_build_query($query);

        // Rebuild URL
        $url = '';
        if (isset($parsed['scheme'])) {
            $url .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['host'])) {
            $url .= $parsed['host'];
        }
        if (isset($parsed['port'])) {
            $url .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $url .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $url .= '?' . $parsed['query'];
        }
        if (isset($parsed['fragment'])) {
            $url .= '#' . $parsed['fragment'];
        }

        return $url;
    }
}
