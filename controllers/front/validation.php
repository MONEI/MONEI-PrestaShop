<?php


class MoneiPaymentPlatformValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active)
            Tools::redirect('index.php?controller=order&step=1');


        $authorized = false;
        foreach (Module::getPaymentModules() as $module)
            if ($module['name'] == 'MoneiPaymentPlatform') {
                $authorized = true;
                break;
            }
        if (!$authorized)
            die($this->module->l('This payment method is not available.', 'validation'));

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer))
            Tools::redirect('index.php?controller=order&step=1');

        $currency = $this->context->currency;
        $total = (float)$cart->getOrderTotal(true, Cart::BOTH);


        //validate order
        $resourcePath = Tools::getValue('resourcePath');
        if (!isset($resourcePath) || $resourcePath == null || empty($resourcePath)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $paymentStatus = $this->module->getPaymentStatus($resourcePath);
        $paymentStatusObj = json_decode($paymentStatus);

        $paymentStatus = Configuration::get('PS_OS_PAYMENT');
        $message = "Thanks for your order!";

        if (!isset($paymentStatusObj->id) || !isset($paymentStatusObj->paymentType) || !isset($paymentStatusObj->paymentBrand)
            || !isset($paymentStatusObj->amount) || !isset($paymentStatusObj->currency)
        ) {
            //there is an error
            $paymentStatus = Configuration::get('PS_OS_ERROR');
            if (isset($paymentStatusObj->result) && isset($paymentStatusObj->result->description)) {
                $message = $paymentStatusObj->result->description;
            } else {
                $message = "An Unkown error occurred while processing your payment.";
            }

        }

        $this->module->validateOrder($cart->id, $paymentStatus, $total, $this->module->displayName, $message, array(), (int)$currency->id, false, $customer->secure_key);
        Tools::redirect('index.php?controller=order-confirmation&id_cart=' . $cart->id . '&id_module=' . $this->module->id . '&id_order=' . $this->module->currentOrder . '&key=' . $customer->secure_key);
    }
}
