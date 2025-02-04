<?php

class AdminMoneiController extends ModuleAdminController
{
    /**
     * Process the "refund" action for AJAX requests
     */
    public function ajaxProcessRefund()
    {
        $amount = Tools::getValue('amount');
        $orderId = (int) Tools::getValue('id_order');
        $reason = Tools::getValue('reason');

        try {
            $multiplier = 100;
            if (Tools::strpos($amount, '.') !== false) {
                $multiplier = 1;
                $amount = sprintf('%.2f', $amount);
            }
            $amount = str_replace([',', '.'], '', $amount) * $multiplier;

            $this->module->getService('service.monei')
                ->createRefund($orderId, $amount, $reason);
            $this->module->getService('service.order')
                ->updateOrderStateAfterRefund($orderId);

            die(json_encode([
                'code' => 200,
                'message' => $this->l('Refunded successfully'),
            ]));
        } catch (Exception $e) {
            die();
        }
    }
}
