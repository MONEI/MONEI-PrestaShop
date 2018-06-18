<?php

class MoneiPaymentsPaymentModuleFrontController extends ModuleFrontController
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

        $config = $this->module->getConfig();

        if (empty($config->secretToken)) {
            Tools::redirect('index.php?controller=order&step=1');

        }

        $apiHandler = new ApiHandler($config->secretToken);
        $currency = Currency::getCurrency($cart->id_currency)['iso_code'];
        $amount = (float)$cart->getOrderTotal(true, Cart::BOTH);
        $billing = new Address($cart->id_address_invoice);
        $billingCountry = new Country($billing->id_country);
        $shipping = new Address($cart->id_address_delivery);
        $shippingCountry = new Country($shipping->id_country);
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
            'customer.phone' => $billing->phone,
            'customer.companyName' => $billing->company,
            'billing.country' => $billingCountry->iso_code,
            'billing.city' => $billing->city,
            'billing.postcode' => $billing->postcode,
            'billing.street1' => $billing->address1,
            'billing.street2' => $billing->address2,
            'shipping.country' => $shippingCountry->iso_code,
            'shipping.city' => $shipping->city,
            'shipping.postcode' => $shipping->postcode,
            'shipping.street1' => $shipping->address1,
            'shipping.street2' => $shipping->address2,
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

