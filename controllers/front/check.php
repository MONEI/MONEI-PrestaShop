<?php

use Monei\CoreClasses\Monei as MoneiClass;
use Monei\CoreHelpers\PsCartHelper;
use Monei\CoreHelpers\PsOrderHelper;
use Monei\Model\MoneiPaymentMethods;
use Monei\MoneiClient;

// Load libraries
require_once dirname(__FILE__) . '/../../vendor/autoload.php';

class MoneiCheckModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $this->ajax = true;
        parent::initContent();
    }

    /**
     * Check if payment is done, using the cart id
     * @return json
     */
    public function displayAjaxPayment()
    {
        $id_cart = (int)Tools::getValue('cart_id');
        $counter = (int)Tools::getValue('counter');
        $order_exists = false;

        // Check if the cart belongs to the logged in customer
        $cart = new Cart($id_cart);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog(
                'MONEI: Cart not found - Cart ID: ' . $id_cart,
                PrestaShopLogger::LOG_SEVERITY_LEVEL_MAJOR,
                null,
                'MoneiCheckModuleFrontController',
            );

            header('HTTP/1.0 403 Forbidden');
            echo '<h1>Operation failed.</h1>';
            echo '<h2>The operation could not be completed, please contact the administrator.</h2>';
            exit;
        }
        $id_customer_cart = $cart->id_customer;

        if ((int)Context::getContext()->customer->id !== (int)$id_customer_cart) {
            PrestaShopLogger::addLog(
                'MONEI: Customer not correspond to cart customer - Context customer ID: ' . Context::getContext()->customer->id . ' - Cart customer ID: ' . $id_customer_cart,
                PrestaShopLogger::LOG_SEVERITY_LEVEL_MAJOR,
                null,
                'MoneiCheckModuleFrontController',
            );

            header('HTTP/1.0 403 Forbidden');
            echo '<h1>Operation failed.</h1>';
            echo '<h2>The operation could not be completed, please contact the administrator.</h2>';
            exit;
        }

        $orderExists = false;
        $orderIdCreated = null;
        $orderByCart = Order::getByCartId($id_cart);
        if (Validate::isLoadedObject($orderByCart)) {
            $orderExists = true;
            $orderIdCreated = $orderByCart->id;
        }

        die(json_encode([
            'order_exists' => $orderExists,
            'id_order' => $orderIdCreated,
            'counter' => $counter,
        ]));
    }

    /**
     * Convert cart to order
     */
    public function displayAjaxConvert()
    {
        $order_id = Tools::getValue('order_id', ''); // XXXXXXXXmYYY
        $id_cart = (int)Tools::getValue('cart_id');
        $id_order_monei = Tools::getValue('monei_id'); // API ID

        $cart = new Cart($id_cart);
        $id_customer_cart = $cart->id_customer;

        if ((int)Context::getContext()->customer->id !== (int)$id_customer_cart) {
            // Simulate 404
            header('HTTP/1.1 404 Not Found');
            die();
        }

        $lbl_monei = MoneiClass::getMoneiByMoneiOrder($id_order_monei);
        if ((int)$lbl_monei->id_cart !== $id_cart) {
            // Simulate 404
            header('HTTP/1.1 404 Not Found');
            die();
        }

        $id_lbl_monei = $lbl_monei->id;

        $error = [];
        if (Tools::isSubmit('error')) {
            $error[] = $this->context->cookie->monei_error;
        }

        if (Tools::isSubmit('success') && Tools::isSubmit('message')) {
            $error[] = Tools::getValue('message');
        }

        $order = Order::getByCartId($id_cart);
        $already_failed = PsCartHelper::checkIfAlreadyFailed($cart->id);

        try {
            $message = '';
            $cart = new Cart($id_cart);
            $failed = false;

            $client = new MoneiClient(Configuration::get('MONEI_API_KEY'));
            $payment_from_api = $client->payments->getPayment($id_order_monei);

            // Check customer
            $id_customer_cart = (int)$cart->id_customer;
            $customer = new Customer($id_customer_cart);
            $amount_cart = (int)PsCartHelper::getTotalFromCart($id_cart);

            if ($amount_cart !== (int)$payment_from_api->getAmount()) {
                // Validate order with error
                $error[] = $this->l('Expected payment amount doesnt match response amount');
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $lbl_monei->status = 'FAILED';
            }

            // Check Currencies
            if ($lbl_monei->currency !== $payment_from_api->getCurrency()) {
                $error[] = $this->l('Currency from response and internal registry doesnt match');
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $lbl_monei->status = 'FAILED';
            }

            // Check payment (FROM API call, not callback)
            if ($payment_from_api->getStatusCode() === 'E000') {
                $payment_status = (int)Configuration::get('MONEI_STATUS_SUCCEEDED');
                $lbl_monei->status = pSQL($payment_from_api->getStatus());
            } else {
                // Payment KO, TODO ERRORS ($message)
                $error[] = $payment_from_api->getStatusMessage();
                $payment_status = (int)Configuration::get('MONEI_STATUS_FAILED');
                $lbl_monei->status = 'FAILED';
                $failed = true;
            }

            $lbl_monei->save();

            $should_create_order = true;
            $order_state_obj = new OrderState(Configuration::get('MONEI_STATUS_PENDING'));
            $paymentMethodUsed = $payment_from_api->getPaymentMethod()->getMethod();

            if (Configuration::get('MONEI_CART_TO_ORDER') || $paymentMethodUsed === MoneiPaymentMethods::MULTIBANCO) {
                $should_create_order = false;
                if (
                    Configuration::get('MONEI_STATUS_PENDING') &&
                    Validate::isLoadedObject($order_state_obj)
                ) {
                    if (!$already_failed) {
                        // Change order status to paid/failed
                        $order = Order::getByCartId($id_cart);
                        $order->setCurrentState($payment_status);
                    }
                }
            } else {
                // Can be an unvalidated order due MONEI delay
                $order = Order::getByCartId($id_cart) ?? new Order();
                if ($order->id > 0) {
                    $should_create_order = false;
                } elseif ((int)$order->id === 0 && $failed === true && !Configuration::get('MONEI_CART_TO_ORDER')) {
                    $should_create_order = false;
                } elseif ((int)$order->id === 0 && $failed === false) {
                    $should_create_order = true;
                }
            }
            if ($should_create_order && !PsOrderHelper::orderExists($id_cart)) {
                // Set a LOCK for slow servers
                $is_locked_info = MoneiClass::getLockInformation($lbl_monei->id);

                if ($is_locked_info['locked'] == 0) {
                    Db::getInstance()->update(
                        'monei',
                        [
                            'locked' => 1,
                            'locked_at' => time(),
                        ],
                        'id_monei = ' . (int)$id_lbl_monei
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
                        'id_monei = ' . (int)$id_lbl_monei
                    );
                }

                if ($should_create_order) {
                    $this->module->validateOrder(
                        $id_cart,
                        $payment_status,
                        $payment_from_api->getAmount() / 100,
                        $this->module->displayName,
                        $message,
                        ['transaction_id' => $payment_from_api->getId()],
                        $cart->id_currency,
                        false,
                        $customer->secure_key
                    );

                    $order = Order::getByCartId($id_cart);
                }
            }

            if (!Validate::isLoadedObject($order)) {
                PrestaShopLogger::addLog(
                    'MONEI: Not is possible to get order.',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_MAJOR,
                    null,
                    'MoneiCheckModuleFrontController',
                );

                header('HTTP/1.0 403 Forbidden');
                echo '<h1>Operation failed.</h1>';
                echo '<h2>The operation could not be completed, please contact the administrator.</h2>';
                exit;
            }

            // Check id_order
            if ($order->id > 0) {
                $lbl_monei->id_order = (int)$order->id;
                $lbl_monei->save();
            }

            // Save log (required from API for tokenization)
            if (!$already_failed) {
                if (!PsOrderHelper::saveTransaction($payment_from_api, false, false, false, $failed)) {
                    $message = $this->l('Unable to save transaction information');
                    $error[] = $message;
                    PrestaShopLogger::addLog('MONEI: ' . $message, 3);
                }
            }
        } catch (Exception $ex) {
            $failed = true;
            $error[] = $ex->getMessage();
        }
        $this->context->smarty->assign([
            'errors' => $error,
            'monei_success' => !$failed,
            'order_id' => $order_id,
            'id_order' => $order->id,
            'order' => $order
        ]);

        $content_tpl = $this->module->fetch('module:monei/views/templates/front/confirmation.tpl');

        die(json_encode([
            'content' => $content_tpl,
            'order_reference' => $order->reference,
        ]));
    }
}
