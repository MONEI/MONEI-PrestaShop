<?php
class MoneiCreatePaymentModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        if (!$this->isAuthorizedRequest()) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized access']);
            exit;
        }

        $paymentResponse = $this->module->createPayment();
        if ($paymentResponse) {
            header('Content-Type: application/json');
            echo json_encode(['moneiPaymentId' => $paymentResponse->getId()]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Payment creation failed']);
        }
        exit;
    }

    private function isAuthorizedRequest()
    {
        $json = file_get_contents('php://input');

        $data = json_decode($json, true);

        if (isset($data['token'])) {
            return $data['token'] === Tools::getToken(false);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Token not provided']);
        }
    }
}
