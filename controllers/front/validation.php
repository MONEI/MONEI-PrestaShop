<?php

use PsMonei\Repository\ConfigurationStore;
use PsMonei\Service\Monei\CallbackVerifier;
use PsMonei\Service\Monei\MoneiClientFactory;
use PsMonei\Service\Monei\MoneiService;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiValidationModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // If the module is not active anymore, no need to process anything.
        if (!$this->module->active) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Service Unavailable';
            exit;
        }

        // Enforce POST-only webhook endpoint
        if (Tools::strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            header('Allow: POST');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Method Not Allowed';
            exit;
        }

        $verifier = $this->callbackVerifier();

        // A shop with a scoped key verifies a callback without the header by fetching the payment.
        if (!isset($_SERVER['HTTP_MONEI_SIGNATURE']) && !$verifier->anyModeConnected()) {
            Monei::logWarning('[MONEI] Missing webhook signature header');
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Unauthorized';
            exit;
        }

        $requestBody = (string) Tools::file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_MONEI_SIGNATURE']) ? (string) $_SERVER['HTTP_MONEI_SIGNATURE'] : null;
        $decision = $verifier->verify($requestBody, $signature);

        if ($decision['payment'] === null) {
            if ($decision['status'] === 401) {
                Monei::logError('[MONEI] Webhook signature verification failed');
            } else {
                Monei::logWarning('[MONEI] Webhook not processed [reason=' . $decision['reason']
                    . (isset($decision['detail']) ? ', detail=' . $decision['detail'] : '') . ']');
            }

            http_response_code($decision['status']);
            header('Content-Type: text/plain; charset=utf-8');
            echo [200 => 'OK', 401 => 'Unauthorized'][$decision['status']] ?? 'Not processed';
            exit;
        }

        // Only the payment the verifier returns is processed, never the request body.
        $payment = $decision['payment'];

        try {
            // An allowlist: a payment also holds customer and card data.
            Monei::logDebug('[MONEI] Webhook received [payment_id=' . $payment->getId()
                . ', order_id=' . $payment->getOrderId()
                . ', status=' . $payment->getStatus()
                . ', status_code=' . $payment->getStatusCode()
                . ', amount=' . $payment->getAmount()
                . ', currency=' . $payment->getCurrency()
                . ', livemode=' . ($payment->getLivemode() ? '1' : '0') . ']');

            Monei::getService('service.order')->createOrUpdateOrder($payment->getId(), false, $payment);

            Monei::logDebug('[MONEI] Webhook processed successfully [payment_id=' . $payment->getId() . ']');

            // Success response
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'OK';
        } catch (Exception $e) {
            Monei::logError('[MONEI] Webhook processing error: ' . $e->getMessage());

            http_response_code(400);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Bad Request';
        }

        exit;
    }

    private function callbackVerifier(): CallbackVerifier
    {
        return new CallbackVerifier(
            new ConfigurationStore(),
            (int) Shop::getContextShopGroupID(true),
            (int) Shop::getContextShopID(true),
            'time',
            function (string $paymentId): ?array {
                $row = Db::getInstance()->getRow(
                    'SELECT p.`id_cart`, p.`id_order_monei`, p.`status`, c.`id_shop_group`, c.`id_shop`'
                    . ' FROM `' . _DB_PREFIX_ . 'monei2_payment` p'
                    . ' LEFT JOIN `' . _DB_PREFIX_ . 'cart` c ON c.`id_cart` = p.`id_cart`'
                    . ' WHERE p.`id_payment` = \'' . pSQL($paymentId) . '\'',
                    false
                );
                if (!$row) {
                    return null;
                }
                $idOrder = (int) Order::getIdByCartId((int) $row['id_cart']);
                $order = $idOrder ? new Order($idOrder) : null;

                return [
                    'orderId' => (string) $row['id_order_monei'],
                    'status' => (string) $row['status'],
                    'monei' => $order === null || $order->module === $this->module->name,
                    'paid' => $order !== null && $order->hasBeenPaid() > 0,
                    'idShopGroup' => (int) $row['id_shop_group'],
                    'idShop' => (int) $row['id_shop'],
                ];
            },
            static function (string $apiKey, string $paymentId) {
                return MoneiClientFactory::create($apiKey, MoneiService::getUserAgent())->payments->get($paymentId);
            }
        );
    }
}
