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
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized access']);
            exit;
        }

        // Get payment method from request if provided
        $paymentMethod = isset($data['paymentMethod']) ? $data['paymentMethod'] : '';

        // Debug cart state
        PrestaShopLogger::addLog('MONEI - createPayment - Cart ID: ' . $this->context->cart->id, PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);
        PrestaShopLogger::addLog('MONEI - createPayment - Cart products count: ' . count($this->context->cart->getProducts()), PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);
        PrestaShopLogger::addLog('MONEI - createPayment - Customer ID: ' . $this->context->cart->id_customer, PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE);

        $paymentResponse = Monei::getService('service.monei')->createMoneiPayment(
            $this->context->cart,
            false,  // tokenizeCard
            0,      // cardTokenId
            $paymentMethod
        );
        if ($paymentResponse) {
            header('Content-Type: application/json');
            echo json_encode(['moneiPaymentId' => $paymentResponse->getId()]);
        } else {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['error' => 'Payment creation failed']);
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
