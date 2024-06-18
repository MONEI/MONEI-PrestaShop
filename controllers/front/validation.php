<?php

use Monei\ApiException;
use Monei\CoreClasses\Monei;
use Monei\CoreHelpers\PsCartHelper;
use Monei\CoreHelpers\PsOrderHelper;
use Monei\Model\MoneiPayment;
use Monei\MoneiClient;
use Monei\Traits\ValidationHelpers;

// Load libraries
require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiValidationModuleFrontController extends ModuleFrontController
{
    use ValidationHelpers;

    public function initContent()
    {
        $this->setTemplate('module:' . $this->module->name . '/views/templates/front/redirect.tpl');
        parent::initContent();
    }

    /**
     * This class should be use by your Instant Payment
     * Notification system to validate the order remotely
     */
    public function postProcess()
    {
        /*
         * If the module is not active anymore, no need to process anything.
         */
        if ($this->module->active == false) {
            die();
        }

        $data = Tools::file_get_contents('php://input');

        try {
            $message = '';

            $payment_status = (int)Configuration::get('MONEI_STATUS_PENDING');

            // Try to convert the response into a valid JSON object
            $json_array = $this->vJSON($data);
            $payment_callback = new MoneiPayment($json_array);

            // Check which cart we need to convert into an order
            $order_id = $payment_callback->getOrderId();
            $id_monei = Monei::getIdByInternalOrder($order_id);
            if (!$id_monei) {
                $message = $this->l('Unknown ID for orderId') . ': ' . pSQL($order_id);
                PrestaShopLogger::addLog('MONEI: ' . $message, 3);
                throw new ApiException($message, 3);
                die();
            }

            $monei = new Monei((int)$id_monei);
            $authorization_code = $payment_callback->getAuthorizationCode();
            $monei->authorization_code = pSQL($authorization_code);
            $monei->save();

            $client = new MoneiClient(Configuration::get('MONEI_API_KEY'), Configuration::get('MONEI_ACCOUNT_ID'));
            $payment_from_api = $client->payments->getPayment($monei->id_order_monei);

            $id_cart = (int)$monei->id_cart;
            $id_order_internal = $monei->id_order_internal;
            $id_cart_response = is_array(explode('m', $order_id)) ? (int)explode('m', $order_id)[0] : false;

            $amount_response = $payment_callback->getAmount();

            // Check orderId
            if ($id_order_internal !== $order_id) {
                $message = $this->l('orderId from response and internal registry doesnt match');
                PrestaShopLogger::addLog('MONEI: ' . $message, 3);
                throw new ApiException($message, 4);
                die();
            }
            // Check Cart
            if ($id_cart !== $id_cart_response) {
                $message = $this->l('cartId from response and internal registry doesnt match');
                PrestaShopLogger::addLog('MONEI: ' . $message, 3);
                throw new ApiException($message, 5);
                die();
            }

            $cart = new Cart($id_cart);

            // Check customer
            $id_customer_cart = (int)$cart->id_customer;
            $customer = new Customer($id_customer_cart);

            $amount_cart = (int)PsCartHelper::getTotalFromCart($id_cart);
            $failed = false;

            // Triple check for amount
            if ($amount_response !== (int)$monei->amount || $amount_response !== $amount_cart) {
                // Validate order with error
                $message = $this->l('Expected payment amount doesnt match response amount');
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $monei->status = 'FAILED';
            }

            if ($amount_response !== (int)$payment_from_api->getAmount()) {
                // Validate order with error
                $message = $this->l('Expected payment amount doesnt match response amount');
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $monei->status = 'FAILED';
            }

            // Check Currencies
            if ($monei->currency !== $payment_from_api->getCurrency()) {
                throw new ApiException($this->l('Currency from response and internal registry doesnt match'), 5);
            }

            // Check payment (FROM API call, not callback)
            if ($payment_from_api->getStatus() === $this->module::MONEI_STATUS_SUCCEEDED) {
                $payment_status = (int)Configuration::get('MONEI_STATUS_SUCCEEDED');
                $monei->status = pSQL($payment_callback->getStatus());
            } else {
                // Payment KO, TODO ERRORS ($message)
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $monei->status = 'FAILED';
                $failed = true;
            }

            $monei->save();

            $should_create_order = true;
            $orderByCart = Order::getByCartId($id_cart);
            if (Validate::isLoadedObject($orderByCart)) {
                if ($orderByCart->id > 0) {
                    $should_create_order = false;
                } elseif ((int)$orderByCart->id === 0 && $failed === true && !Configuration::get('MONEI_CART_TO_ORDER')) {
                    $should_create_order = false;
                } elseif ((int)$orderByCart->id === 0 && $failed === false) {
                    $should_create_order = true;
                }
            }

            $order_state_obj = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
            if (Configuration::get('MONEI_CART_TO_ORDER')) {
                if (
                    Configuration::get('MONEI_STATUS_PENDING') &&
                    Validate::isLoadedObject($order_state_obj)
                ) {
                    $should_create_order = false;
                    // Change order status to paid/failed
                    $orderByCart = Order::getByCartId($id_cart);
                    if (Validate::isLoadedObject($orderByCart)) {
                        $orderByCart->setCurrentState($payment_status);
                    }
                }
            }
            if ($should_create_order && !PsOrderHelper::orderExists($id_cart)) {
                // Set a LOCK for slow servers
                $is_locked_info = Monei::getLockInformation($id_monei);

                if ($is_locked_info['locked'] == 0) {
                    Db::getInstance()->update(
                        'monei',
                        [
                            'locked' => 1,
                            'locked_at' => time(),
                        ],
                        'id_monei = ' . (int)$id_monei
                    );
                } elseif ($is_locked_info['locked'] == 1 && $is_locked_info['locked_at'] < (time() - 60)) {
                    $should_create_order = false;
                    $message = $this->l('Slow server detected, order in creation process');
                    PrestaShopLogger::addLog('MONEI: ' . $message, 3);
                } elseif ($is_locked_info['locked'] == 1 && $is_locked_info['locked_at'] > (time() - 60)) {
                    $message = $this->l('Slow server detected, previous order creation process timed out');
                    PrestaShopLogger::addLog('MONEI: ' . $message, 2);
                    Db::getInstance()->update(
                        'monei',
                        [
                            'locked_at' => time(),
                        ],
                        'id_monei = ' . (int)$id_monei
                    );
                }

                if ($should_create_order) {
                    $this->module->validateOrder(
                        $id_cart,
                        $payment_status,
                        $amount_response / 100,
                        $this->module->displayName,
                        $message,
                        ['transaction_id' => $payment_from_api->getId()],
                        $cart->id_currency,
                        false,
                        $customer->secure_key
                    );
                }

                $orderByCart = Order::getByCartId($id_cart);
            }

            // Check id_order
            if (Validate::isLoadedObject($orderByCart) && $orderByCart->id > 0) {
                $monei->id_order = (int)$orderByCart->id;
                $monei->save();
            }

            // Save log (required from API for tokenization)
            if (!PsOrderHelper::saveTransaction($payment_from_api, false, false, true, $failed)) {
                $message = $this->l('Unable to save transaction information');
                PrestaShopLogger::addLog('MONEI: ' . $message);
                throw new ApiException($message, 2);
            }

            // Check the Payment ID
        } catch (ApiException $ex) {
            PrestaShopLogger::addLog('MONEI: ' . $ex->getMessage(), 4);
        } catch (Exception $ex) {
            PrestaShopLogger::addLog($ex->getMessage());
        }
    }
}
