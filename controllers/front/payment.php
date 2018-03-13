<?php

class MoneiPaymentPlatformPaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();
        $cart = $this->context->cart;
        if ($cart->id_customer == 0 ||
            $cart->id_address_delivery == 0 ||
            $cart->id_address_invoice == 0 ||
            !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'moneipaymentplatform') {
                $authorized = true;
                break;
            }
        }
        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'moneipaymentplatform'));
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $config = $this->module->getConfig();

        if (empty($config->secretToken)) {
            Tools::redirect('index.php?controller=order&step=1');

        }

        $apiHandler = new ApiHandler($config->secretToken);
        $currency = Currency::getCurrency($cart->id_currency)['iso_code'];
        $amount = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $orderId = $cart->id;
        $checkoutParams = array(
            'amount' => $amount,
            'currency' => $currency,
            'merchantInvoiceId' => $orderId,
            'paymentType' => 'DB',
            'customer.merchantCustomerId' => $customer->id,
            'customer.email' => $customer->email,
            'customer.givenName' => $customer->firstname,
            'customer.surname' => $customer->lastname,
        );
        $checkout = $apiHandler->prepareCheckout($checkoutParams);
        if (!isset($checkout->id)) {
            Tools::redirect('index.php?controller=order&step=1');
        }
        $brands = implode(' ', $config->brands);
        $locale = $this->context->language->locale;
		$returnUrl = $this->context->link->getModuleLink($this->module->name, 'validation', array(), true);
		$paymentParams = array(
            'checkoutId' => $checkout->id,
            'redirectUrl' => $returnUrl,
            'locale' => $locale,
            'brands' => $brands
        );

		$paymentUrl = $apiHandler->getPaymentUrl($paymentParams);
		Tools::redirect($paymentUrl);
	}
}

