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
            $cart = $this->context->cart;
            $transactionId = Tools::getValue('transaction_id');
            $tokenizeCard = (bool) Tools::getValue('tokenize_card', false);
            $moneiCardId = (int) Tools::getValue('id_monei_card', 0);
            $paymentMethod = Tools::getValue('method', '');

            // Validate cart exists and is valid before using it
            if (!$cart || !Validate::isLoadedObject($cart)
                || $cart->id_customer == 0
                || $cart->id_address_delivery == 0
                || $cart->id_address_invoice == 0
                || !$this->module->active
            ) {
                \Monei::logError('[MONEI] Payment initiation failed - Invalid or missing cart [method=' . $paymentMethod . ']');
                Tools::redirect($this->context->link->getPageLink('index'));
                exit;
            }


            $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
            $check_encrypt = $crypto->checkHash((int) $cart->id . (int) $cart->id_customer, $transactionId);

            try {
                if (!$check_encrypt) {
                }

                $moneiService = Monei::getService('service.monei');

                $moneiPayment = $moneiService->createMoneiPayment($cart, $tokenizeCard, $moneiCardId, $paymentMethod);
                if (!$moneiPayment) {
                    \Monei::logError('[MONEI] Payment creation failed - No payment object returned [cart_id=' . $cart->id . ']');

                    // Store user-friendly error message for display on checkout page
                    $this->context->cookie->monei_checkout_error = $this->module->l('Unable to process payment. Please try again or use a different payment method.');
                    $this->context->cookie->write();

                    Tools::redirect($this->context->link->getPageLink('order'));
                    exit;
                }

                \Monei::logError('[MONEI] Payment created successfully [payment_id=' . $moneiPayment->getId() . ', cart_id=' . $cart->id . ', status=' . $moneiPayment->getStatus() . ']');

                $nextAction = $moneiPayment->getNextAction();
                $redirectURL = $nextAction ? $nextAction->getRedirectUrl() : null;
                if ($redirectURL) {
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
                \Monei::logError('[MONEI] Payment creation exception [cart_id=' . $cart->id . ', error=' . $ex->getMessage() . ']');

                // If it's a MoneiException with a payment response, try to extract status code
                if ($ex instanceof MoneiException && method_exists($ex, 'getPaymentData')) {
                    $paymentData = $ex->getPaymentData();
                    if ($paymentData && isset($paymentData['statusCode'])) {
                        $this->context->cookie->monei_error_code = $paymentData['statusCode'];
                        // Status code handler will provide localized message
                        Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors'));
                        exit;
                    }
                }

                // For other exceptions, provide a user-friendly generic message
                // Don't expose technical details to users
                $this->context->cookie->monei_checkout_error = $this->module->l('Payment could not be processed. Please try again or contact support.');
                $this->context->cookie->write();
                Tools::redirect($this->context->link->getPageLink('order'));
                exit;
            }
        } catch (Exception $ex) {
            \Monei::logError('[MONEI] Redirect controller critical error [error=' . $ex->getMessage() . ']');
            // Handle outer exception - don't expose technical details
            $this->context->cookie->monei_checkout_error = $this->module->l('An error occurred while processing your payment. Please try again.');
            $this->context->cookie->write();
            Tools::redirect($this->context->link->getPageLink('order'));
            exit;
        }

        exit;
    }

    /**
     * Safely add a query parameter to a URL
     *
     * @param string $url The URL to modify
     * @param string $key The parameter key
     * @param string $value The parameter value
     *
     * @return string The modified URL
     */
    private function addQueryParam(string $url, string $key, string $value): string
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
