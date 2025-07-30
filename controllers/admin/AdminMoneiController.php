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
            // Normalise to a dot, keep two decimals, convert to cents
            $normalized = str_replace(',', '.', $amount);
            $amount = (int) round((float) $normalized * 100);

            $this->module->getService('service.monei')
                ->createRefund($orderId, $amount, $this->context->employee->id, $reason);
            $this->module->getService('service.order')
                ->updateOrderStateAfterRefund($orderId);

            $this->ajaxRenderJson([
                'code' => 200,
                'message' => $this->module->l('Refunded successfully', 'AdminMoneiController'),
            ]);
        } catch (Exception $e) {
            $this->ajaxRenderJson([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);
        }
    }
}
