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
                        $redirectURL .= '&message=' . $moneiPayment->getStatusMessage();
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
}
