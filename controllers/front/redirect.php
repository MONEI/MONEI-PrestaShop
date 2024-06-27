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

        $transaction_id = Tools::getValue('transaction_id');
        $tokenize = (bool) Tools::getValue('tokenize_card');
        $id_monei_card = (int) Tools::getValue('id_monei_card', 0);

        $crypto = ServiceLocator::get('\\PrestaShop\\PrestaShop\\Core\\Crypto\\Hashing');
        $cart = $this->context->cart;
        $check_encrypt = $crypto->checkHash((int)$cart->id . (int)$cart->id_customer, $transaction_id);

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

            $moneiClient = new MoneiClient(
                Configuration::get('MONEI_API_KEY'),
                Configuration::get('MONEI_ACCOUNT_ID')
            );
            $moneiPayment = $this->module->createPaymentObject();

            if (
                $tokenize && (bool) Configuration::get('MONEI_TOKENIZE') === true
                && $id_monei_card === 0
            ) {
                $moneiPayment->setGeneratePaymentToken($tokenize);
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
                    $moneiPayment->setPaymentToken($tokenized_card->tokenized);
                    $moneiPayment->setGeneratePaymentToken(false); // Safe
                }
            }

            // Save the information before sending it to the API
            PsOrderHelper::saveTransaction($moneiPayment, true);

            $response = $moneiClient->payments->createPayment($moneiPayment);
            if (!$response) {
                throw new ApiException('Invalid response from MONEI');
            }

            // Save the information after sending it to the API
            PsOrderHelper::saveTransaction($response);

            // Save the Payment ID
            $moneiOrderInternalId = Monei::getIdByInternalOrder($response->getOrderId());

            $monei = new Monei($moneiOrderInternalId);
            $monei->id_cart = (int) $cart->id;
            $monei->id_order_monei = pSQL($response->getId());
            $monei->save();

            // Convert the cart to order
            $orderState = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
            if (Configuration::get('MONEI_CART_TO_ORDER') && Validate::isLoadedObject($orderState)) {
                $customer = new Customer($cart->id_customer);
                $currency = new Currency($this->context->cart->id_currency);
                $currency_decimals = is_array($currency) ?
                    (int)$currency['decimals'] : (int) $currency->decimals;
                $cart_details = $this->context->cart->getSummaryDetails(null, true);
                $decimals = $currency_decimals * _PS_PRICE_DISPLAY_PRECISION_;
                $shipping = $cart_details['total_shipping_tax_exc'];
                $subtotal = $cart_details['total_price_without_tax'] -
                    $cart_details['total_shipping_tax_exc'];
                $total_tax = $cart_details['total_tax'];
                $total_price = Tools::ps_round($shipping + $subtotal + $total_tax, $decimals);
                $amount = (int) number_format($total_price, 2, '', '');

                $paymentMethod = Tools::getValue('method', 'card');

                $this->module->validateOrder(
                    $cart->id,
                    $orderState->id,
                    $amount / 100,
                    'MONEI ' . $paymentMethod,
                    null,
                    array(),
                    $cart->id_currency,
                    false,
                    $customer->secure_key
                );

                // Check id_order and save it
                $orderId = (int) Order::getIdByCartId($cart->id);
                if ($orderId) {
                    $monei->id_order = $orderId;
                    $monei->save();
                }
            }

            if ($response->getNextAction()->getMustRedirect()) {
                $redirectURL = $response->getNextAction()->getRedirectUrl();
                if ($response->getStatus() === MoneiPaymentStatus::FAILED) {
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

        exit;
    }

    protected function displayError()
    {
        return $this->setTemplate('error.tpl');
    }
}
