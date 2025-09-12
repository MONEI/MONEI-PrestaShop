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
                    ', status=' . $paymentResponse->getStatus() . 
                    ', status_code=' . $paymentResponse->getStatusCode() . ']',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
                );
                
                // Check if payment has failed status
                if ($paymentResponse->getStatus() === 'FAILED') {
                    $errorMessage = 'Payment failed';
                    
                    // Get localized error message based on status code
                    if ($paymentResponse->getStatusCode()) {
                        $statusCodeHandler = Monei::getService('service.status_code_handler');
                        $errorMessage = $statusCodeHandler->getStatusMessage($paymentResponse->getStatusCode());
                    } elseif ($paymentResponse->getStatusMessage()) {
                        $errorMessage = $paymentResponse->getStatusMessage();
                    }
                    
                    PrestaShopLogger::addLog(
                        '[MONEI] Payment failed with status code [payment_id=' . $paymentResponse->getId() . 
                        ', status_code=' . $paymentResponse->getStatusCode() . 
                        ', message=' . $errorMessage . ']',
                        PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
                    );
                    
                    header('Content-Type: application/json');
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Payment failed',
                        'message' => $errorMessage,
                        'statusCode' => $paymentResponse->getStatusCode()
                    ]);
                } else {
                    // Payment succeeded or is pending
                    header('Content-Type: application/json');
                    echo json_encode(['moneiPaymentId' => $paymentResponse->getId()]);
                }
            } else {
                // Payment creation returned false - check for specific error
                $lastError = Monei::getService('service.monei')->getLastError();
                PrestaShopLogger::addLog(
                    '[MONEI] Payment creation via API failed [cart_id=' . $this->context->cart->id . 
                    ', error=' . ($lastError ?: 'Unknown error') . ']',
                    PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
                );
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'error' => 'Payment creation failed',
                    'message' => $lastError ?: 'Unknown error'
                ]);
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            PrestaShopLogger::addLog(
                '[MONEI] Payment creation API exception [cart_id=' . $this->context->cart->id . ', error=' . $errorMessage . ']',
                PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'error' => 'Payment creation error',
                'message' => $errorMessage
            ]);
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
