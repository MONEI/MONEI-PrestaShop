<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
class MoneiCreatePaymentModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        // Read input stream once
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);

        // Pass data to authorization check
        if (!$this->isAuthorizedRequest($data)) {
            PrestaShopLogger::addLog(
                '[MONEI] CreatePayment unauthorized access attempt [cart_id=' . $this->context->cart->id . ']',
                Monei::getLogLevel('warning')
            );
            header('Content-Type: application/json');
            http_response_code(403);

            // Provide specific error based on the issue
            if ($data === null) {
                echo json_encode(['error' => 'Invalid request data']);
            } elseif (!isset($data['token'])) {
                echo json_encode(['error' => 'Token not provided']);
            } else {
                echo json_encode(['error' => 'Invalid token']);
            }
            exit;
        }

        // Get payment method from request if provided
        $paymentMethod = isset($data['paymentMethod']) ? $data['paymentMethod'] : '';

        try {
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
                http_response_code(400);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Payment creation failed']);
            }
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MONEI] createPayment error: ' . $e->getMessage(),
                Monei::getLogLevel('error')
            );
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Payment creation failed']);
        }

        exit;
    }

    private function isAuthorizedRequest($data)
    {
        // Treat null data as invalid request
        if ($data === null) {
            return false;
        }

        if (isset($data['token'])) {
            return $data['token'] === Tools::getToken(false);
        }

        return false;
    }
}
