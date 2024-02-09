<?php


use Monei\ApiException;
use Monei\CoreClasses\Monei as MoneiClass;
use Monei\CoreHelpers\PsOrderHelper;
use Monei\Model\MoneiRefundPayment;
use Monei\Model\MoneiRefundReason;
use Monei\MoneiClient;

class AdminMoneiController extends ModuleAdminController
{
    /**
     * Process the "refund" action for AJAX requests
     */
    public function ajaxProcessRefund()
    {
        $amount = Tools::getValue('amount');
        $id_order = (int)Tools::getValue('id_order');
        $reason = Tools::getValue('reason');

        try {
            $multiplier = 100;
            if (Tools::strpos($amount, '.') !== false) {
                $multiplier = 1;
                $amount = sprintf('%.2f', $amount);
            }
            $amount = str_replace([',', '.'], '', $amount) * $multiplier;
            $monei_refund = $this->createRefundObject($amount, $id_order, $reason);
            if (!$monei_refund) {
                throw new ApiException($this->l('Order not found or invalid refund reason'));
            }

            $client = new MoneiClient(Configuration::get('MONEI_API_KEY'));
            $response = $client->payments->refundPayment($monei_refund);
            PsOrderHelper::saveTransaction($response, false, true);
            $this->changeOrderState($id_order);

            return $this->ajaxResponse([
                'code' => 200,
                'message' => $this->l('Refunded successfully'),
            ]);
        } catch (ApiException $e) {
            die();
        }
    }

    /**
     *
     * @param mixed $amount
     * @param mixed $id_order
     * @param mixed $reason
     * @return false|MoneiRefundPayment
     */
    private function createRefundObject(
        $amount,
        $id_order,
        $reason
    )
    {
        // Get the ID MONEI order from id_order
        $id_order_monei = MoneiClass::getIdOrderMoneiByIdOrder($id_order);

        if (!$id_order_monei || !in_array($reason, MoneiRefundReason::getAllowableEnumValues())) {
            return false;
        }

        $monei_refund = new MoneiRefundPayment();
        $monei_refund->setAmount($amount);
        $monei_refund->setId($id_order_monei);
        $monei_refund->setRefundReason($reason);

        return $monei_refund;
    }

    /**
     * Change the order state to refunded (if activated)
     * @param int $id_order
     * @return void
     */
    private function changeOrderState($id_order)
    {
        if ((int)Configuration::get('MONEI_SWITCH_REFUNDS') === 0) {
            return;
        }

        $order = new Order($id_order);
        $total_refunded = MoneiClass::getTotalRefundedByIdOrder($id_order);
        $total_refunded_decimal = (int)$total_refunded > 0 ? $total_refunded / 100 : 0;
        $total_paid = $order->getTotalPaid();
        if ($total_refunded_decimal < $total_paid) {
            $order->setCurrentState(Configuration::get('MONEI_STATUS_PARTIALLY_REFUNDED'));
        } else {
            $order->setCurrentState(Configuration::get('MONEI_STATUS_REFUNDED'));
        }
    }

    /**
     *  Returns AJAX response in JSON
     * @param array $data
     * @return json
     */
    private function ajaxResponse($data)
    {
        die(json_encode($data));
    }
}
