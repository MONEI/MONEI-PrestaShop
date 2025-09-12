<?php
use Monei\Model\PaymentStatus;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use PsMonei\Exception\MoneiException;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function postProcess()
    {
        try {
            $transactionId = Tools::getValue('transaction_id');
            $tokenizeCard = (bool) Tools::getValue('tokenize_card', false);
            $moneiCardId = (int) Tools::getValue('id_monei_card', 0);
            $paymentMethod = Tools::getValue('method', '');

            PrestaShopLogger::addLog('MONEI - Redirect - Starting payment process', PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);

            $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
            $cart = $this->context->cart;
            $check_encrypt = $crypto->checkHash((int) $cart->id . (int) $cart->id_customer, $transactionId);

            if ($cart->id_customer == 0
                || $cart->id_address_delivery == 0
                || $cart->id_address_invoice == 0
                || !$this->module->active
            ) {
                Tools::redirect($this->context->link->getPageLink('index'));
            }

            try {
                if (!$check_encrypt) {
                    throw new MoneiException('Invalid crypto hash', MoneiException::INVALID_CRYPTO_HASH);
                }

                $moneiService = Monei::getService('service.monei');

                $moneiPayment = $moneiService->createMoneiPayment($cart, $tokenizeCard, $moneiCardId, $paymentMethod);
                if (!$moneiPayment) {
                    Tools::redirect($this->context->link->getPageLink('order'));
                }


                if ($redirectURL = $moneiPayment->getNextAction()->getRedirectUrl()) {
                    if ($moneiPayment->getStatus() === PaymentStatus::FAILED) {
                        // Store status code for failed payments before redirect
                        if ($moneiPayment->getStatusCode()) {
                            $this->context->cookie->monei_error_code = $moneiPayment->getStatusCode();
                        }
                        // Safely append the message parameter using proper URL handling
                        if ($statusMessage = $moneiPayment->getStatusMessage()) {
                            $redirectURL = $this->addQueryParam($redirectURL, 'message', $statusMessage);
                        }
                    }

                    Tools::redirect($redirectURL);
                }
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
        } catch (Exception $ex) {
            // Handle outer exception
            $this->context->cookie->monei_error = $ex->getMessage();
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors'));
        }

        exit;
    }

    /**
     * Safely add a query parameter to a URL
     * 
     * @param string $url The URL to modify
     * @param string $key The parameter key
     * @param string $value The parameter value
     * @return string The modified URL
     */
    private function addQueryParam($url, $key, $value)
    {
        $urlParts = parse_url($url);
        
        // Parse existing query parameters
        $queryParams = [];
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $queryParams);
        }
        
        // Add/update the parameter
        $queryParams[$key] = $value;
        
        // Rebuild query string - http_build_query handles encoding automatically
        $urlParts['query'] = http_build_query($queryParams);
        
        // Rebuild the URL using the built-in function if available
        if (function_exists('http_build_url')) {
            return http_build_url($urlParts);
        }
        
        // Manual rebuild if http_build_url is not available
        $url = '';
        if (isset($urlParts['scheme'])) {
            $url .= $urlParts['scheme'] . '://';
        }
        if (isset($urlParts['user'])) {
            $url .= $urlParts['user'];
            if (isset($urlParts['pass'])) {
                $url .= ':' . $urlParts['pass'];
            }
            $url .= '@';
        }
        if (isset($urlParts['host'])) {
            $url .= $urlParts['host'];
        }
        if (isset($urlParts['port'])) {
            $url .= ':' . $urlParts['port'];
        }
        if (isset($urlParts['path'])) {
            $url .= $urlParts['path'];
        }
        if (isset($urlParts['query'])) {
            $url .= '?' . $urlParts['query'];
        }
        if (isset($urlParts['fragment'])) {
            $url .= '#' . $urlParts['fragment'];
        }
        
        return $url;
    }
}
