<?php

class AdminMoneiController extends ModuleAdminController
{
    /** @var monei */
    public $module;

    /**
     * @param string $content
     *
     * @throws PrestaShopException
     */
    protected function ajaxRenderJson($content)
    {
        header('Content-Type: application/json');
        $this->ajaxRender(json_encode($content));
    }

    /**
     * Process the "refund" action for AJAX requests
     */
    public function displayAjaxRefund()
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
                ->createRefund($orderId, $amount, $this->context->employee->id, $reason);
            $this->module->getService('service.order')
                ->updateOrderStateAfterRefund($orderId);

            $this->ajaxRenderJson([
                'code' => 200,
                'message' => $this->l('Refunded successfully'),
            ]);
        } catch (Exception $e) {
            $this->ajaxRenderJson([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
