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
        // Use PS1.7 compatible encryption
        $expected_hash = Tools::encrypt((int) $cart->id . (int) $cart->id_customer);
        $check_encrypt = ($expected_hash === $transactionId);

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

            // Convert the cart to order
            $orderState = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
            if (Configuration::get('MONEI_CART_TO_ORDER') && Validate::isLoadedObject($orderState)) {
                $orderService = Monei::getService('service.order');
                $orderService->createOrUpdateOrder($moneiPayment->getId());
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

        exit;
    }
}
