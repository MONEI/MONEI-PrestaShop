<?php


class MoneiPaymentsValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 ||
            $cart->id_address_delivery == 0 ||
            $cart->id_address_invoice == 0 ||
            !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'moneipayments') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'moneipayments'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);

        $config = $this->module->getConfig();

        if (empty($config->secretToken)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $apiHandler = new ApiHandler($config->secretToken);

        //validate order
        $resourcePath = Tools::getValue('resourcePath');
        if (!isset($resourcePath) || $resourcePath == null || empty($resourcePath)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $transaction = $apiHandler->getTransactionStatus($resourcePath);

        if ($apiHandler->isTransactionSuccessful($transaction)) {
            $paymentStatus = Configuration::get('PS_OS_PAYMENT');
            $message = "Successful payment!";
        } else {
            $paymentStatus = Configuration::get('PS_OS_ERROR');

            if (isset($transaction->result) && isset($transaction->result->description)) {
                $message = $transaction->result->description;
            } else {
                $message = $this->module->l("An unknown error occurred while processing payment.", 'moneipayments');
            }
        }

        $this->module->validateOrder($cart->id,
            $paymentStatus,
            $total,
            $this->module->displayName,
            $message,
            array(),
            (int)$currency->id,
            false,
            $customer->secure_key);


        $redirectParams = array(
            'controller' => 'order-confirmation',
            'id_cart' => $cart->id,
            'id_module' => $this->module->id,
            'id_order' => $transaction->merchantInvoiceId,
            'key' => $customer->secure_key
        );

        Tools::redirect('index.php' . '?' . http_build_query($redirectParams));
    }
}
