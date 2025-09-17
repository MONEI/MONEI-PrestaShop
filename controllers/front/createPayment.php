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
            \Monei::logWarning('[MONEI] CreatePayment unauthorized access attempt [cart_id=' . $this->context->cart->id . ']');
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

        try {
            $paymentResponse = Monei::getService('service.monei')->createMoneiPayment(
                $this->context->cart,
                false,  // tokenizeCard
                0,      // cardTokenId
                $paymentMethod
            );

            if ($paymentResponse) {

                // Always return the payment ID, even if status is FAILED
                // The JavaScript will handle the failure through confirmPayment
                // Important: Cast to string to ensure it's a simple type for JSON encoding
                $paymentId = (string) $paymentResponse->getId();
                header('Content-Type: application/json');
                echo json_encode(['moneiPaymentId' => $paymentId]);
            } else {
                // Payment creation returned false - check for specific error
                $lastError = Monei::getService('service.monei')->getLastError();
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

            \Monei::logError('[MONEI] Payment creation API exception [cart_id=' . $this->context->cart->id
                . ', error=' . $errorMessage
                . ', status_code=' . ($statusCodeValue ?: 'unknown') . ']');

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
            http_response_code($statusCode);
            header('Content-Type: application/json');
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
