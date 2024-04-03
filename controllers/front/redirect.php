<?php

use Monei\ApiException;
use Monei\CoreClasses\Monei;
use Monei\CoreClasses\MoneiCard;
use Monei\CoreHelpers\PsOrderHelper;
use Monei\Model\MoneiAddress;
use Monei\Model\MoneiBillingDetails;
use Monei\Model\MoneiCustomer;
use Monei\Model\MoneiPayment;
use Monei\Model\MoneiPaymentMethods;
use Monei\Model\MoneiShippingDetails;
use Monei\MoneiClient;
use Monei\Model\MoneiPaymentStatus;
use PrestaShop\PrestaShop\Adapter\ServiceLocator;
use PrestaShop\PrestaShop\Core\Localization\Exception\LocalizationException;

// Load libraries
require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        $cart = $this->context->cart;
        if (
            $cart->id_customer == 0 || $cart->id_address_delivery == 0 ||
            $cart->id_address_invoice == 0 || !$this->module->active
        ) {
            Tools::redirect($this->context->link->getPageLink('index'));
        }

        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/redirect.tpl');
    }

    /**
     * Do whatever you have to before redirecting the customer on the website of your payment processor.
     */
    public function postProcess()
    {
        /*
         * Oops, an error occured.
         */
        if (Tools::getValue('action') === 'error') {
            return $this->displayError();
        }

        $transaction_id = Tools::getValue('transaction_id');
        $tokenize = (bool)Tools::getValue('monei_tokenize_card');
        $id_monei_card = (int)Tools::getValue('id_monei_card', 0);

        $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $cart = $this->context->cart;
        $check_encrypt = $crypto->checkHash((int)$cart->id . (int)$cart->id_customer, $transaction_id);

        if ($check_encrypt) {
            try {
                $client = new MoneiClient(Configuration::get('MONEI_API_KEY'));
                $payment = $this->createPaymentObject();
                if (
                    $tokenize && (bool)Configuration::get('MONEI_TOKENIZE') === true
                    && $id_monei_card === 0
                ) {
                    $payment->setGeneratePaymentToken($tokenize);
                }
                // If we need to set a token
                if ($id_monei_card > 0) {
                    // Check if this card belongs to the customer
                    $belongs_to_customer = MoneiCard::belongsToCustomer(
                        $id_monei_card,
                        $this->context->customer->id
                    );

                    if ($belongs_to_customer) {
                        $tokenized_card = new MoneiCard($id_monei_card);
                        $payment->setPaymentToken($tokenized_card->tokenized);
                        $payment->setGeneratePaymentToken(false); // Safe
                    }
                }

                $response = $client->payments->createPayment($payment);
                PsOrderHelper::saveTransaction($response);

                // Save the information before sending it to the API
                $transaction = PsOrderHelper::saveTransaction($payment, true);

                if ($transaction && $response) {
                    // Save the Payment ID
                    $id_lbl_monei = Monei::getIdByInternalOrder($response->getOrderId());
                    $lbl_monei = new Monei($id_lbl_monei);
                    $lbl_monei->id_cart = (int)$cart->id;
                    $lbl_monei->id_order_monei = pSQL($response->getId());
                    $lbl_monei->save();

                    // Convert the cart to order
                    $order_state_obj = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
                    if (Configuration::get('MONEI_CART_TO_ORDER')) {
                        if (
                            Configuration::get('MONEI_STATUS_PENDING') &&
                            Validate::isLoadedObject($order_state_obj)
                        ) {
                            $customer = new Customer($cart->id_customer);
                            $currency = new Currency($this->context->cart->id_currency);
                            $currency_decimals = is_array($currency) ?
                                (int)$currency['decimals'] : (int)$currency->decimals;
                            $cart_details = $this->context->cart->getSummaryDetails(null, true);
                            $decimals = $currency_decimals * _PS_PRICE_DISPLAY_PRECISION_;
                            $shipping = $cart_details['total_shipping_tax_exc'];
                            $subtotal = $cart_details['total_price_without_tax'] -
                                $cart_details['total_shipping_tax_exc'];
                            $total_tax = $cart_details['total_tax'];
                            $total_price = Tools::ps_round($shipping + $subtotal + $total_tax, $decimals);
                            $amount = (int)number_format($total_price, 2, '', '');

                            $this->module->validateOrder(
                                $cart->id,
                                Configuration::get('MONEI_STATUS_PENDING'),
                                $amount / 100,
                                $this->module->displayName,
                                null,
                                array(),
                                $cart->id_currency,
                                false,
                                $customer->secure_key
                            );

                            // We must update the order_id
                            $id_order = (int)Order::getIdByCartId($cart->id);
                            if ($id_order > 0) {
                                $lbl_monei->id_order = $id_order;
                                $lbl_monei->save();
                            }
                        }
                    }
                }

                if ($response->getNextAction()->getMustRedirect()) {
                    $redirectURL = $response->getNextAction()->getRedirectUrl();
                    if ($response->getStatusCode() === MoneiPaymentStatus::FAILED) {
                        $redirectURL .= '&message=' . $response->getStatusMessage();
                    }

                    Tools::redirect($redirectURL);
                }
            } catch (ApiException $ex) {
                $this->context->cookie->monei_error = 'API: ' . $ex->getMessage();
                Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors', [
                    'cart_id' => (int)$cart->id
                ]));
            } catch (Exception $ex) {
                $this->context->cookie->monei_error = 'API: ' . $ex->getMessage();
                Tools::redirect($this->context->link->getModuleLink($this->module->name, 'errors', [
                    'cart_id' => (int)$cart->id
                ]));
            }
        }
    }

    protected function displayError()
    {
        return $this->setTemplate('error.tpl');
    }

    /**
     * Creates the Payment object.
     * @return MoneiPayment
     * @throws PrestaShopException
     * @throws LocalizationException
     * @throws PrestaShopDatabaseException
     */
    private function createPaymentObject(): MoneiPayment
    {
        $currency = new Currency($this->context->cart->id_currency);
        $currency_iso = $currency->iso_code;
        $currency_decimals = is_array($currency) ? (int)$currency['decimals'] : (int)$currency->decimals;
        $cart_details = $this->context->cart->getSummaryDetails(null, true);
        $decimals = $currency_decimals * _PS_PRICE_DISPLAY_PRECISION_; // _PS_PRICE_DISPLAY_PRECISION_ deprec 1.7.7 TODO
        $shipping = $cart_details['total_shipping_tax_exc'];
        $subtotal = $cart_details['total_price_without_tax'] - $cart_details['total_shipping_tax_exc'];
        $total_tax = $cart_details['total_tax'];
        $total_price = Tools::ps_round($shipping + $subtotal + $total_tax, $decimals);
        $amount = (int)number_format($total_price, 2, '', '');
        $order_id = str_pad($this->context->cart->id . 'm' . time() % 1000, 12, '0', STR_PAD_LEFT); // Redsys/Bizum Style

        // URLs
        $url_ok = $this->context->link->getModuleLink($this->module->name, 'confirmation', [
            'success' => 1,
            'cart_id' => $this->context->cart->id,
            'order_id' => $order_id
        ]);
        $url_ko = $this->context->link->getModuleLink($this->module->name, 'confirmation', [
            'success' => 0,
            'cart_id' => $this->context->cart->id,
            'order_id' => $order_id
        ]);

        $url_cancel = $this->context->link->getPageLink('order', null, null, 'step=3');
        $url_callback = $this->context->link->getModuleLink($this->module->name, 'validation');

        // Models
        $monei_payment = new MoneiPayment();
        $monei_customer = new MoneiCustomer();

        $monei_address_billing = new MoneiAddress();
        $monei_billing_details = new MoneiBillingDetails();

        $monei_address_shipping = new MoneiAddress();
        $monei_shipping_details = new MoneiShippingDetails();

        $id_address_invoice = (int)$this->context->cart->id_address_invoice;
        $id_address_delivery = (int)$this->context->cart->id_address_delivery;

        $ps_address_invoice = new Address($id_address_invoice);
        $ps_address_delivery = new Address($id_address_delivery);

        $id_lang = (int)$this->context->language->id;

        $state_invoice = (int)$ps_address_invoice->id_state > 0 ?
            new State($ps_address_invoice->id_state, $id_lang) : new State();
        $state_delivery = (int)$ps_address_delivery->id_state > 0 ?
            new State($ps_address_invoice->id_state, $id_lang) : new State();
        $state_invoice_name = $state_invoice->name ?: '';
        $state_delivery_name = $state_delivery->name ?: '';

        $country_invoice = (int)$ps_address_invoice->id_country > 0 ?
            new Country($ps_address_invoice->id_country, $id_lang) : new Country();
        $country_delivery = (int)$ps_address_delivery->id_country > 0 ?
            new Country($ps_address_invoice->id_country, $id_lang) : new Country();
        $country_invoice_iso = $country_invoice->iso_code ?: '';
        $country_delivery_iso = $country_delivery->iso_code ?: '';


        $monei_customer
            ->setName($this->context->customer->lastname . ', ' . $this->context->customer->firstname)
            ->setEmail($this->context->customer->email)
            ->setPhone($ps_address_invoice->phone);

        $monei_address_billing
            ->setLine1($ps_address_invoice->address1)
            ->setLine2($ps_address_invoice->address2)
            ->setZip($ps_address_invoice->postcode)
            ->setCity($ps_address_invoice->city)
            ->setState($state_invoice_name)
            ->setCountry($country_invoice_iso);

        $monei_address_shipping
            ->setLine1($ps_address_delivery->address1)
            ->setLine2($ps_address_delivery->address2)
            ->setZip($ps_address_delivery->postcode)
            ->setCity($ps_address_delivery->city)
            ->setState($state_delivery_name)
            ->setCountry($country_delivery_iso);

        $monei_billing_details
            ->setName($monei_customer->getName())
            ->setEmail($monei_customer->getEmail())
            ->setPhone($monei_customer->getPhone())
            ->setAddress($monei_address_billing);

        $monei_shipping_details
            ->setName($monei_customer->getName())
            ->setEmail($monei_customer->getEmail())
            ->setPhone($monei_customer->getPhone())
            ->setAddress($monei_address_shipping);

        $monei_payment
            ->setAmount($amount)
            ->setCurrency($currency_iso)
            ->setOrderId($order_id)
            ->setCompleteUrl($url_ok)
            ->setFailUrl($url_ko)
            ->setCallbackUrl($url_callback)
            ->setCancelUrl($url_cancel)
            ->setBillingDetails($monei_billing_details)
            ->setShippingDetails($monei_shipping_details);

        // Check for available payment methods
        $payment_methods = [];

        if (!Configuration::get('MONEI_ALLOW_ALL')) {
            if (Tools::isSubmit('method')) {
                $param_method = Tools::getValue('method', 'card');
                $payment_methods[] = in_array($param_method, MoneiPaymentMethods::getAllowableEnumValues()) ?
                    $param_method : 'card'; // Fallback card
            } else {
                if (Configuration::get('MONEI_ALLOW_CARD')) {
                    $payment_methods[] = 'card';
                }
                if (Configuration::get('MONEI_ALLOW_BIZUM')) {
                    $payment_methods[] = 'bizum';
                }
                if (Configuration::get('MONEI_ALLOW_APPLE')) {
                    $payment_methods[] = 'applePay';
                }
                if (Configuration::get('MONEI_ALLOW_GOOGLE')) {
                    $payment_methods[] = 'googlePay';
                }
                if (Configuration::get('MONEI_ALLOW_CLICKTOPAY')) {
                    $payment_methods[] = 'clickToPay';
                }
                if (Configuration::get('MONEI_ALLOW_PAYPAL')) {
                    $payment_methods[] = 'paypal';
                }
                if (Configuration::get('MONEI_ALLOW_COFIDIS')) {
                    $payment_methods[] = 'cofidis';
                }
                if (Configuration::get('MONEI_ALLOW_KLARNA')) {
                    $payment_methods[] = 'klarna';
                }
                if (Configuration::get('MONEI_ALLOW_MULTIBANCO')) {
                    $payment_methods[] = 'multibanco';
                }
            }
        }

        $monei_payment->setAllowedPaymentMethods($payment_methods);
        return $monei_payment;
    }
}
