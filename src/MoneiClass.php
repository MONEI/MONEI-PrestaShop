<?php
namespace PsMonei;

use ObjectModel;
use PrestaShopLogger;
use OpenAPI\Client\Model\Payment;

class MoneiClass extends ObjectModel
{
    public $id_payment;
    public $id_cart;
    public $id_order_prestashop;
    public $id_order_monei;
    public $amount;
    public $currency;
    public $authorization_code;
    public $status;
    public $date_add;
    public $date_upd;

    public static $definition = array(
        'table' => 'monei',
        'primary' => 'id_payment',
        'fields' => array(
            'id_cart' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'id_order_prestashop' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt'),
            'id_order_monei' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'size' => 50),
            'amount' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt'),
            'currency' => array('type' => self::TYPE_STRING, 'size' => 3),
            'authorization_code' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'size' => 50),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'size' => 20),
            'date_add' => array('type' => self::TYPE_DATE),
            'date_upd' => array('type' => self::TYPE_DATE),
        ),
    );

    public function savePaymentResponse(Payment $moneiPaymentResponse)
    {
        if (property_exists($moneiPaymentResponse, 'id')) {
            $this->id_payment = $moneiPaymentResponse->getId();
        }
        if (property_exists($moneiPaymentResponse, 'getOrderId')) {
            $this->id_order_monei = $moneiPaymentResponse->getOrderId();

            // Extracting cart ID from the formatted order ID
            $this->id_cart = (int) substr($this->id_order_monei, 0, strpos($this->id_order_monei, 'm'));
        }
        if (property_exists($moneiPaymentResponse, 'amount')) {
            $this->amount = $moneiPaymentResponse->getAmount();
        }
        if (property_exists($moneiPaymentResponse, 'currency')) {
            $this->currency = $moneiPaymentResponse->getCurrency();
        }
        if (property_exists($moneiPaymentResponse, 'authorizationCode')) {
            $this->authorization_code = $moneiPaymentResponse->getAuthorizationCode();
        }
        if (property_exists($moneiPaymentResponse, 'status')) {
            $this->status = $moneiPaymentResponse->getStatus();
        }

        try {
            $this->add();
        } catch (\Exception $e) {
            PrestaShopLogger::addLog('MoneiClass::savePaymentResponse - Error saving payment response: ' . $e->getMessage(), PrestaShopLogger::LOG_LEVEL_ERROR);
        }

        return $this;
    }
}
