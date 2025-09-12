<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
class MoneiCreatePaymentModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // Read input once to avoid issues with read-once streams
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        
        if (!$this->isAuthorizedRequest($data)) {
            PrestaShopLogger::addLog(
                '[MONEI] CreatePayment unauthorized access attempt [cart_id=' . $this->context->cart->id . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized access']);
            exit;
        }

        // Get payment method from request if provided
        $paymentMethod = isset($data['paymentMethod']) ? $data['paymentMethod'] : '';

        // Log payment creation attempt with context
        $cartProducts = $this->context->cart->getProducts();
        PrestaShopLogger::addLog(
            '[MONEI] CreatePayment API called [cart_id=' . $this->context->cart->id . 
            ', customer_id=' . $this->context->cart->id_customer . 
            ', products=' . count($cartProducts) . 
            ', method=' . $paymentMethod . 
            ', total=' . $this->context->cart->getOrderTotal(true, Cart::BOTH) . ']',
            PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        try {
            $paymentResponse = Monei::getService('service.monei')->createMoneiPayment(
                $this->context->cart,
                false,  // tokenizeCard
                0,      // cardTokenId
                $paymentMethod
            );
            
            if ($paymentResponse) {
                PrestaShopLogger::addLog(
                    '[MONEI] Payment created via API [payment_id=' . $paymentResponse->getId() . 
                    ', cart_id=' . $this->context->cart->id . 
                    ', status=' . $paymentResponse->getStatus() . ']',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
                header('Content-Type: application/json');
                echo json_encode(['moneiPaymentId' => $paymentResponse->getId()]);
            } else {
                PrestaShopLogger::addLog(
                    '[MONEI] Payment creation via API failed [cart_id=' . $this->context->cart->id . ']',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['error' => 'Payment creation failed']);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MONEI] Payment creation API exception [cart_id=' . $this->context->cart->id . ', error=' . $e->getMessage() . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['error' => 'Payment creation error']);
        }
        exit;
    }

    private function isAuthorizedRequest($data = null)
    {
        // If data not provided, read it (backward compatibility)
        if ($data === null) {
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);
        }

        if (isset($data['token'])) {
            return $data['token'] === Tools::getToken(false);
        } else {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'Token not provided']);
            return false;
        }
    }
}
