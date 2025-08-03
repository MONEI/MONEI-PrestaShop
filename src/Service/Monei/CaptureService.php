<?php

namespace PsMonei\Service\Monei;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Monei\Model\CapturePaymentRequest;
use Monei\Model\Payment;
use PsMonei\Exception\MoneiException;
use PsMonei\Repository\MoneiHistoryRepository;
use PsMonei\Repository\MoneiPaymentRepository;

class CaptureService extends AbstractApiService
{
    private $moneiPaymentRepository;
    private $moneiHistoryRepository;

    public function __construct(
        MoneiPaymentRepository $moneiPaymentRepository,
        MoneiHistoryRepository $moneiHistoryRepository,
    ) {
        $this->moneiPaymentRepository = $moneiPaymentRepository;
        $this->moneiHistoryRepository = $moneiHistoryRepository;
    }

    /**
     * Capture an authorized payment
     *
     * @param int $orderId The PrestaShop order ID
     * @param int|null $amount Amount to capture in cents (null for full amount)
     *
     * @return Payment The captured payment
     *
     * @throws MoneiException
     */
    public function capturePayment(int $orderId, ?int $amount = null)
    {
        \PrestaShopLogger::addLog(
            '[Capture] Starting capture process for order: ' . $orderId,
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Find the MONEI payment record
        $moneiPayment = $this->moneiPaymentRepository->findOneBy(['id_order' => $orderId]);
        if (!$moneiPayment) {
            \PrestaShopLogger::addLog(
                '[Capture] Payment record not found for order: ' . $orderId,
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            throw new MoneiException('Payment record not found for order', MoneiException::ORDER_NOT_FOUND);
        }

        $paymentId = $moneiPayment->getId();
        if (empty($paymentId)) {
            \PrestaShopLogger::addLog(
                '[Capture] Payment ID is empty for order: ' . $orderId,
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_ERROR
            );

            throw new MoneiException('Payment ID is empty', MoneiException::PAYMENT_ID_EMPTY);
        }

        \PrestaShopLogger::addLog(
            '[Capture] Payment status check - Order: ' . $orderId . ', Status: ' . $moneiPayment->getStatus() . ', Payment ID: ' . $paymentId,
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Check if payment is already captured (both by status and is_captured flag)
        if ($moneiPayment->getStatus() === 'SUCCEEDED' || $moneiPayment->getIsCaptured()) {
            \PrestaShopLogger::addLog(
                '[Capture] Payment already captured - Order: ' . $orderId . ', Payment ID: ' . $paymentId
                . ', Status: ' . $moneiPayment->getStatus() . ', IsCaptured: ' . ($moneiPayment->getIsCaptured() ? 'true' : 'false'),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
            );

            throw new MoneiException('Payment is already captured', MoneiException::PAYMENT_ALREADY_CAPTURED);
        }

        // Check if payment is in authorized state
        if ($moneiPayment->getStatus() !== 'AUTHORIZED') {
            \PrestaShopLogger::addLog(
                '[Capture] Payment not in authorized state - Order: ' . $orderId . ', Status: ' . $moneiPayment->getStatus(),
                \PrestaShopLogger::LOG_SEVERITY_LEVEL_WARNING
            );

            throw new MoneiException('Payment is not in authorized state', MoneiException::PAYMENT_NOT_AUTHORIZED);
        }

        // If no amount specified, capture full amount
        if ($amount === null) {
            $amount = $moneiPayment->getAmount();
        }

        // Validate capture amount
        if ($amount <= 0) {
            throw new MoneiException('Capture amount must be greater than zero', MoneiException::INVALID_CAPTURE_AMOUNT);
        }

        if ($amount > $moneiPayment->getAmount()) {
            throw new MoneiException('Capture amount exceeds authorized amount', MoneiException::CAPTURE_AMOUNT_EXCEEDS_AUTHORIZED);
        }

        \PrestaShopLogger::addLog(
            '[Capture] Payment request - Order: ' . $orderId . ', Amount: ' . $amount . ' cents',
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Create capture request
        $captureRequest = new CapturePaymentRequest();
        $captureRequest->setAmount($amount);

        // Execute capture with proper error handling
        $capturedPayment = $this->executeApiCall(
            'Capture',
            function () use ($paymentId, $captureRequest) {
                $moneiClient = $this->getMoneiClient();

                return $moneiClient->payments->capture($paymentId, $captureRequest);
            },
            [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'amount' => $amount,
            ]
        );

        \PrestaShopLogger::addLog(
            '[Capture] Payment response - Order: ' . $orderId . ', Capture ID: ' . $capturedPayment->getId() . ', Status: ' . $capturedPayment->getStatus(),
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        // Update the payment record
        $this->updatePaymentRecord($moneiPayment, $capturedPayment);

        \PrestaShopLogger::addLog(
            '[Capture] Payment captured successfully - Order: ' . $orderId . ', Capture ID: ' . $capturedPayment->getId(),
            \PrestaShopLogger::LOG_SEVERITY_LEVEL_INFORMATIVE
        );

        return $capturedPayment;
    }

    /**
     * Update payment record after capture
     *
     * @param \PsMonei\Entity\Monei2Payment $moneiPayment
     * @param Payment $capturedPayment
     */
    private function updatePaymentRecord($moneiPayment, Payment $capturedPayment)
    {
        $moneiPayment->setStatus($capturedPayment->getStatus());
        $moneiPayment->setStatusCode($capturedPayment->getStatusCode());
        $moneiPayment->setAuthorizationCode($capturedPayment->getAuthorizationCode());
        $moneiPayment->setIsCaptured(true);
        $moneiPayment->setDateUpd(time());

        $this->moneiPaymentRepository->save($moneiPayment);

        // Save to history
        $monei2History = new \PsMonei\Entity\Monei2History();
        $monei2History->setPayment($moneiPayment);
        $monei2History->setStatus($capturedPayment->getStatus());
        $monei2History->setStatusCode($capturedPayment->getStatusCode());
        $monei2History->setResponse(json_encode($capturedPayment));
        $monei2History->setDateAdd(new \DateTime());

        $this->moneiHistoryRepository->save($monei2History);
    }
}
