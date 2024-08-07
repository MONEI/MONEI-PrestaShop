<?php


namespace Monei\CoreClasses;

use Configuration;
use Currency;
use Db;
use ObjectModel;
use Order;
use Tools;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei extends ObjectModel
{
    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'monei',
        'primary' => 'id_monei',
        'fields' => array(
            'id_cart' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'id_order' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt'),
            'id_order_monei' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel'),
            'id_order_internal' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel'),
            'amount' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt'),
            'currency' => array('type' => self::TYPE_STRING),
            'authorization_code' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel'),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel'),
            'locked' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'locked_at' => array('type' => self::TYPE_INT),
            'date_add' => array('type' => self::TYPE_DATE),
            'date_upd' => array('type' => self::TYPE_DATE),
        ),
    );
    public $id_monei;
    public $id_cart;
    public $id_order;
    public $id_order_monei;
    public $id_order_internal;
    public $amount;
    public $currency;
    public $authorization_code;
    public $status;
    public $locked;
    public $locked_at;
    public $date_add;
    public $date_upd;

    /**
     * Check if the order is locked
     * @param int $id_monei
     * @return bool
     */
    public static function isLocked($id_monei)
    {
        return (bool)Db::getInstance()->getValue('SELECT locked FROM ' . _DB_PREFIX_ . 'monei WHERE id_monei = '
            . (int)$id_monei);
    }

    /**
     * Get the locked_at value
     * @param int $id_monei
     * @return int
     */
    public static function getLockedAt($id_monei)
    {
        return Db::getInstance()->getValue('SELECT locked_at FROM ' . _DB_PREFIX_ . 'monei WHERE id_monei = '
            . (int)$id_monei);
    }

    /**
     * Get the locked and locked_at values
     * @param int $id_monei
     * @return array
     */
    public static function getLockInformation($id_monei)
    {
        return Db::getInstance()->getRow('SELECT locked, locked_at FROM ' . _DB_PREFIX_
            . 'monei WHERE id_monei = ' . (int)$id_monei);
    }

    /**
     * Get total refunded in X00 format
     * @param mixed $id_monei
     * @return string|false
     */
    public static function getTotalRefundedByIdMonei($id_monei)
    {
        return Db::getInstance()->getValue('SELECT amount FROM ' . _DB_PREFIX_
            . 'monei_refund WHERE id_monei = ' . (int)$id_monei);
    }

    /**
     * Get total refunded in X00 format by id_order
     * @param int $id_order
     * @return int|false
     */
    public static function getTotalRefundedByIdOrder($id_order)
    {
        $id_monei = self::getIdByIdOrder($id_order);
        return Db::getInstance()->getValue('SELECT SUM(amount) FROM ' . _DB_PREFIX_
            . 'monei_refund WHERE id_monei = ' . (int)$id_monei);
    }

    /**
     * Get ID from PS id_order
     * @param mixed $id_order
     * @return string|false
     */
    public static function getIdByIdOrder($id_order)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_monei FROM ' . _DB_PREFIX_ . 'monei WHERE id_order = "' . pSQL($id_order) . '" ORDER BY authorization_code DESC'
        );
    }

    /**
     * Get Currency ISO code from an order
     * @param mixed $id_order
     * @return string
     */
    public static function getISOCurrencyByIdOrder($id_order)
    {
        $order = new Order($id_order);
        $currency = new Currency($order->id_currency);
        return $currency->iso_code;
    }

    /**
     * Gets the id_order from id_monei ID
     * @param mixed $id_monei
     * @return string|false
     */
    public static function getIdOrderByIdMonei($id_monei)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue('
            SELECT `id_order`
            FROM `' . _DB_PREFIX_ . 'monei`
            WHERE `id_monei` = ' . (int)$id_monei);
    }

    /**
     * Gets the table identifier with orderId from Monei
     * @param mixed $id_order_monei
     * @return string|false
     */
    public static function getIdByMoneiOrder($id_order_monei)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_monei FROM ' . _DB_PREFIX_ . 'monei WHERE id_order_monei = "'
            . pSQL($id_order_monei) . '"'
        );
    }

    /**
     * Gets an instance of Monei
     * @param mixed $id_order_monei
     * @return stdClass
     */
    public static function getMoneiByMoneiOrder($id_order_monei)
    {
        if (!$id_order_monei) {
            return new Monei();
        }

        $id_monei = Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_monei FROM ' . _DB_PREFIX_ . 'monei WHERE id_order_monei = "'
            . pSQL($id_order_monei) . '"'
        ) ?? 0;
        return new Monei($id_monei);
    }

    /**
     * Gets the ID order MONEI (payment ID) from an order
     * @param mixed $id_order
     * @return string|false
     */
    public static function getIdOrderMoneiByIdOrder($id_order)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_order_monei FROM ' . _DB_PREFIX_ . 'monei WHERE id_order = '
            . (int)$id_order
        );
    }

    /**
     * Gets the table identifier with orderId from Monei
     * @param mixed $id_order_internal
     * @return string|false
     */
    public static function getIdByInternalOrder($id_order_internal)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getValue(
            'SELECT id_monei FROM ' . _DB_PREFIX_ . 'monei WHERE id_order_internal = "'
            . pSQL($id_order_internal) . '"'
        );
    }

    /**
     * Alias function for getIdByIdOrder
     * @param int $id_order
     * @return int|false
     */
    public static function getIdMoneiByIdOrder($id_order)
    {
        return self::getIdByIdOrder($id_order);
    }

    /**
     * Get detailed information about a refunds by its history ID
     * @param mixed $id_monei_history
     * @return array|bool|object|null
     */
    public static function getRefundDetailByIdMoneiHistory($id_monei_history)
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->getRow(
            'SELECT * FROM ' . _DB_PREFIX_ . 'monei_refund WHERE id_monei_history = '
            . (int)$id_monei_history
        );
    }

    /**
     * Get full log
     * @return array|false
     */
    public function getHistory($without_refunds = true)
    {
        $additional_sql = $without_refunds ? ' AND is_refund = 0' : '';
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'monei_history WHERE id_monei = "' . (int)$this->id
            . '" ' . ($additional_sql) . ' ORDER BY date_add DESC'
        ); // SQL Injection-safe, not user input
    }

    /**
     * Get only refunds
     * @return array|false
     */
    public function getRefundHistory()
    {
        return Db::getInstance(_PS_USE_SQL_SLAVE_)->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'monei_history WHERE id_monei = "' . (int)$this->id
            . '" AND is_refund = 1 ORDER BY date_add DESC'
        );
    }

    /**
     * Return total refunds done to this order
     * @param int $id_order
     * @return int
     */
    public function getTotalRefunded($id_monei)
    {
        return Db::getInstance()->getValue(
            '
            SELECT SUM(amount) FROM ' . _DB_PREFIX_ . 'monei_refund
            WHERE id_monei = ' . (int)$id_monei
        ) ?? 0;
    }

    /**
     * Change the order to invalid payment
     * @param mixed $id_monei
     * @param mixed $id_cart
     * @return bool
     */
    public function changeToFailed($id_monei, $id_cart)
    {
        $id_monei = Db::getInstance()->getValue('SELECT id_monei FROM `' . _DB_PREFIX_
            . 'monei` WHERE id_cart = ' . (int)$id_cart . ' AND id_order_monei = "' . pSQL($id_monei) . '"');
        if ($id_monei) {
            $id_order = (int)Order::getIdByCartId($id_cart);
            if ($id_order > 0) {
                $order = new Order($id_order);
                $order->setCurrentState(Configuration::get('MONEI_STATUS_FAILED'));

                $code = Db::getInstance()->getValue(
                    'SELECT `id_monei_codes` FROM `' . _DB_PREFIX_ . 'monei_codes` WHERE LOWER(`message`) = "'
                    . pSQL(trim(Tools::strtolower(Tools::getValue('message')))) . '"'
                ) ?? null;

                $tmp_code = [];
                if ($code) {
                    $code_msg = Db::getInstance()->getValue('SELECT `message` FROM `' . _DB_PREFIX_
                        . 'monei_codes` WHERE id_monei_codes = ' . (int)$code);
                } else {
                    $code_msg = $this->module->l('Uknown error');
                }

                // Now get the real code
                $status_code = Db::getInstance()->getValue(
                    'SELECT `code` FROM `' . _DB_PREFIX_ . 'monei_codes` WHERE id_monei_codes = '
                    . (int)$code
                );

                $tmp_code = [
                    'id' => $id_monei,
                    'status' => 'FAILED',
                    'status_code' => $status_code,
                    'status_message' => $code_msg,
                ];

                $this->saveHistory($id_monei, $code, $tmp_code);

                return true;
            }
        }
        return false;
    }

    /**
     * Save the history of the failed order
     * @param mixed $id_monei
     * @param mixed $code
     * @param mixed $tmp_code
     * @return void
     */
    private function saveHistory($id_monei, $code, $tmp_code)
    {
        Db::getInstance()->insert('monei_history', [
            'id_monei' => (int)$id_monei,
            'id_monei_code' => (int)$code,
            'status' => 'FAILED',
            'response' => pSQL(json_encode($tmp_code)),
            'date_add' => date('Y-m-d H:i:s')
        ]);

        Db::getInstance()->update('monei', [
            'status' => 'FAILED'
        ], 'id_monei = ' . (int)$id_monei);
    }
}
