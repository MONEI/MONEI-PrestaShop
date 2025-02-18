<?php

use OpenAPI\Client\Model\PaymentStatus;
use PsMonei\ApiException;
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
        if (Tools::getValue('action') === 'error') {
            return $this->displayError();
        }

        $transactionId = Tools::getValue('transaction_id');
        $tokenizeCard = (bool) Tools::getValue('tokenize_card', false);
        $moneiCardId = (int) Tools::getValue('id_monei_card', 0);

        $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $cart = $this->context->cart;
        $check_encrypt = $crypto->checkHash((int) $cart->id . (int) $cart->id_customer, $transactionId);

        if ($cart->id_customer == 0 ||
            $cart->id_address_delivery == 0 ||
            $cart->id_address_invoice == 0 ||
            !$this->module->active
        ) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }

        try {
            if (!$check_encrypt) {
                throw new ApiException('Invalid crypto hash');
            }

            $moneiService = $this->module->getService('service.monei');

            $moneiPayment = $moneiService->createMoneiPayment($cart, $tokenizeCard, $moneiCardId);
            if (!$moneiPayment) {
                Tools::redirect($this->context->link->getPageLink('order'));
            }

            // Convert the cart to order
            $orderState = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
            if (Configuration::get('MONEI_CART_TO_ORDER') && Validate::isLoadedObject($orderState)) {
                $orderService = $this->module->getService('service.order');
                $orderService->createOrUpdateOrder($moneiPayment->getId());
            }

            if ($redirectURL = $moneiPayment->getNextAction()->getRedirectUrl()) {
                if ($moneiPayment->getStatus() === PaymentStatus::FAILED) {
                    $redirectURL .= '&message=' . $moneiPayment->getStatusMessage();
                }

                Tools::redirect($redirectURL);
            }
        } catch (MoneiException $ex) {
            $this->context->cookie->monei_error = 'MONEI: ' . $ex->getMessage();
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors'));
        } catch (Exception $ex) {
            $this->context->cookie->monei_error = $ex->getMessage();
            Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors'));
        }

        exit;
    }

    protected function displayError()
    {
        return $this->setTemplate('error.tpl');
    }
}
