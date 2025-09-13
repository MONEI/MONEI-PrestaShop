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

        // Log payment creation attempt with context
        $cartProducts = $this->context->cart->getProducts();
        PrestaShopLogger::addLog(
            '[MONEI] CreatePayment API called [cart_id=' . $this->context->cart->id
            . ', customer_id=' . $this->context->cart->id_customer
            . ', products=' . count($cartProducts)
            . ', method=' . $paymentMethod
            . ', total=' . $this->context->cart->getOrderTotal(true, Cart::BOTH) . ']',
            Monei::getLogLevel('info')
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
                    '[MONEI] Payment created via API [payment_id=' . $paymentResponse->getId()
                    . ', cart_id=' . $this->context->cart->id
                    . ', status=' . $paymentResponse->getStatus()
                    . ', status_code=' . $paymentResponse->getStatusCode() . ']',
                    Monei::getLogLevel('info')
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
                        '[MONEI] Payment failed with status code [payment_id=' . $paymentResponse->getId()
                        . ', status_code=' . $paymentResponse->getStatusCode()
                        . ', message=' . $errorMessage . ']',
                        Monei::getLogLevel('warning')
                    );

                    header('Content-Type: application/json');
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Payment failed',
                        'message' => $errorMessage,
                        'statusCode' => $paymentResponse->getStatusCode(),
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
                    '[MONEI] Payment creation via API failed [cart_id=' . $this->context->cart->id
                    . ', error=' . ($lastError ?: 'Unknown error') . ']',
                    Monei::getLogLevel('error')
                );
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode([
                    'error' => 'Payment creation failed',
                    'message' => $lastError ?: 'Unknown error',
                ]);
            }
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            $statusCode = 500; // Default to server error
            $statusCodeValue = null;

            // Extract status code from the API response if available
            if ($e instanceof Monei\ApiException) {
                $responseBody = $e->getResponseBody();

                // Parse the response body if it's a JSON string
                if (is_string($responseBody)) {
                    $decoded = json_decode($responseBody);
                    if ($decoded && isset($decoded->statusCode)) {
                        $statusCode = (int) $decoded->statusCode;
                        $statusCodeValue = $statusCode;
                    }
                } elseif (is_object($responseBody) && isset($responseBody->statusCode)) {
                    $statusCode = (int) $responseBody->statusCode;
                    $statusCodeValue = $statusCode;
                }

                // Also get the HTTP response code directly if available
                if (method_exists($e, 'getCode') && $e->getCode() > 0) {
                    // Use the exception code as status if no statusCode in response body
                    if (!$statusCodeValue) {
                        $statusCode = (int) $e->getCode();
                        $statusCodeValue = $statusCode;
                    }
                }
            } elseif ($e instanceof PsMonei\Exception\MoneiException) {
                // For MoneiException, check if there's a previous exception with status code
                $previous = $e->getPrevious();
                if ($previous instanceof Monei\ApiException) {
                    $responseBody = $previous->getResponseBody();

                    // Parse the response body if it's a JSON string
                    if (is_string($responseBody)) {
                        $decoded = json_decode($responseBody);
                        if ($decoded && isset($decoded->statusCode)) {
                            $statusCode = (int) $decoded->statusCode;
                            $statusCodeValue = $statusCode;
                        }
                    } elseif (is_object($responseBody) && isset($responseBody->statusCode)) {
                        $statusCode = (int) $responseBody->statusCode;
                        $statusCodeValue = $statusCode;
                    }
                }
            }

            PrestaShopLogger::addLog(
                '[MONEI] Payment creation API exception [cart_id=' . $this->context->cart->id
                . ', error=' . $errorMessage
                . ', status_code=' . ($statusCodeValue ?: 'unknown') . ']',
                Monei::getLogLevel('error')
            );

            // Prepare response array
            $response = [
                'error' => 'Payment creation error',
                'message' => $errorMessage,
            ];

            // Include status code in response if available
            if ($statusCodeValue) {
                $response['statusCode'] = $statusCodeValue;
            }

            // Log what we're sending to frontend
            PrestaShopLogger::addLog(
                '[MONEI] Sending error response to frontend: ' . json_encode($response),
                Monei::getLogLevel('info')
            );

            header('Content-Type: application/json');
            http_response_code($statusCode);
            echo json_encode($response);
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
