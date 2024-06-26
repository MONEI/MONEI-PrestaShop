<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiCheckModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $this->ajax = true;

        parent::initContent();

        try {
            $cartId = (int) Tools::getValue('cart_id');

            // Check if the cart belongs to the logged in customer
            $cart = new Cart($cartId);
            if (!Validate::isLoadedObject($cart)) {
                PrestaShopLogger::addLog(
                    'MONEI: Cart not found - Cart ID: ' . $cartId,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );

                die(json_encode([
                    'error' => true,
                    'message' => 'MONEI: Cart not found',
                ]));
            }

            $customerCartId = (int) $cart->id_customer;

            // Check if the cart belongs to the logged in customer
            if ((int) Context::getContext()->customer->id !== $customerCartId) {
                PrestaShopLogger::addLog(
                    'MONEI: Customer not correspond to cart customer - Context customer ID: ' . Context::getContext()->customer->id . ' - Cart customer ID: ' . $customerCartId,
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );

                die(json_encode([
                    'error' => true,
                    'message' => 'MONEI: Customer not correspond to cart customer',
                ]));
            }
        } catch (Exception $ex) {
            die(json_encode([
                'error' => true,
                'message' => $ex->getMessage(),
            ]));
        }
    }

    /**
     * Check if payment is done, using the cart id
     * @return json
     */
    public function displayAjaxPayment()
    {
        try {
            $cartId = (int) Tools::getValue('cart_id');
            $counter = (int) Tools::getValue('counter');
            $orderExists = false;

            $orderId = (int) Order::getIdByCartId($cartId);
            if ($orderId) {
                $orderExists = true;
            }

            die(json_encode([
                'error' => false,
                'order_exists' => $orderExists,
                'id_order' => $orderId,
                'counter' => $counter,
            ]));
        } catch (Exception $ex) {
            die(json_encode([
                'error' => true,
                'message' => $ex->getMessage(),
            ]));
        }
    }

    /**
     * Convert cart to order
     */
    public function displayAjaxConvert()
    {
        try {
            $moneiOrderId = Tools::getValue('order_id');
            $cartId = (int) Tools::getValue('cart_id');
            $moneiPaymentId = Tools::getValue('monei_id');

            if (Tools::isSubmit('error')) {
                die(json_encode([
                    'error' => true,
                    'message' => $this->context->cookie->monei_error,
                ]));
            }

            if (Tools::isSubmit('success') && Tools::isSubmit('message')) {
                die(json_encode([
                    'error' => true,
                    'message' => Tools::getValue('message'),
                ]));
            }

            try {
                $this->module->createOrUpdateOrder($moneiPaymentId);
            } catch (Exception $ex) {
                die(json_encode([
                    'error' => true,
                    'message' => $ex->getMessage(),
                ]));
            }

            $smartyParameters = [
                'error' => false,
                'monei_success' => true,
                'order_id' => $moneiOrderId,
            ];

            $order = Order::getByCartId($cartId);
            if (Validate::isLoadedObject($order)) {
                $smartyParameters['id_order'] = $order->id;
                $smartyParameters['order'] = $order;
            }

            $this->context->smarty->assign($smartyParameters);

            $content_tpl = $this->module->fetch('module:monei/views/templates/front/confirmation.tpl');

            die(json_encode([
                'error' => false,
                'content' => $content_tpl,
                'order_reference' => $order->reference,
            ]));

        } catch (Exception $ex) {
            die(json_encode([
                'error' => true,
                'message' => $ex->getMessage(),
            ]));
        }
    }
}
