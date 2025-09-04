<?php

namespace PsMonei\Entity;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei2Payment extends \ObjectModel
{
    public $id_payment;
    public $id_cart;
    public $id_order;
    public $id_order_monei;
    public $amount;
    public $refunded_amount;
    public $currency;
    public $authorization_code;
    public $status;
    public $is_captured = false;
    public $status_code;
    public $date_add;
    public $date_upd;

    /**
     * @var array Object model definition
     */
    public static $definition = [
        'table' => 'monei2_payment',
        'primary' => 'id_payment',
        'fields' => [
            'id_payment' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'required' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'id_order' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'allow_null' => true],
            'id_order_monei' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'allow_null' => true],
            'amount' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true],
            'refunded_amount' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'allow_null' => true],
            'currency' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 3],
            'authorization_code' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'allow_null' => true],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 20, 'allow_null' => true],
            'is_captured' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'status_code' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 10, 'allow_null' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
            'date_upd' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Constructor - override to handle string primary key
     *
     * @param string|null $id
     * @param int|null $id_lang
     * @param int|null $id_shop
     */
    public function __construct($id = null, $id_lang = null, $id_shop = null)
    {
        // For string primary key, we need to handle loading differently
        if ($id) {
            $this->id_payment = $id;
            $this->id = $id; // Set the parent id property

            // Load data manually since ObjectModel expects integer ID
            $sql = 'SELECT * FROM `' . _DB_PREFIX_ . self::$definition['table'] . '` 
                    WHERE `' . self::$definition['primary'] . '` = \'' . pSQL($id) . '\'';

            if ($row = \Db::getInstance()->getRow($sql)) {
                foreach ($row as $key => $value) {
                    if (property_exists($this, $key)) {
                        $this->{$key} = $value;
                    }
                }
            }
        }

        // Don't call parent constructor as it expects integer ID
    }

    /**
     * Override getFields to handle our custom fields without parent validation
     */
    public function getFields()
    {
        $fields = [];

        // Build fields array based on our definition
        foreach (self::$definition['fields'] as $field => $def) {
            // Skip fields that allow null and are actually null
            if (isset($def['allow_null']) && $def['allow_null'] && $this->{$field} === null) {
                continue;
            }

            // Add the field value
            $fields[$field] = $this->{$field};
        }

        return $fields;
    }

    /**
     * Override add to handle string primary key
     */
    public function add($auto_date = true, $null_values = false)
    {
        if ($auto_date && property_exists($this, 'date_add')) {
            $this->date_add = date('Y-m-d H:i:s');
        }
        if ($auto_date && property_exists($this, 'date_upd')) {
            $this->date_upd = date('Y-m-d H:i:s');
        }

        $fields = $this->getFields();
        $keys = array_keys($fields);
        $values = array_values($fields);

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . self::$definition['table'] . '` (`' . implode('`, `', $keys) . '`) 
                VALUES (\'' . implode('\', \'', array_map('pSQL', $values)) . '\')';

        $result = \Db::getInstance()->execute($sql);

        if ($result) {
            $this->id = $this->id_payment;
        }

        return $result;
    }

    /**
     * Override update to handle string primary key
     */
    public function update($null_values = false)
    {
        $this->date_upd = date('Y-m-d H:i:s');

        $fields = $this->getFields();
        unset($fields['id_payment']); // Don't update primary key

        $sql_parts = [];
        foreach ($fields as $key => $value) {
            // Handle null values properly
            if ($value === null) {
                if ($null_values || (isset(self::$definition['fields'][$key]['allow_null']) && self::$definition['fields'][$key]['allow_null'])) {
                    $sql_parts[] = '`' . $key . '` = NULL';
                }
            } else {
                $sql_parts[] = '`' . $key . '` = \'' . pSQL($value) . '\'';
            }
        }

        if (empty($sql_parts)) {
            return true; // Nothing to update
        }

        $sql = 'UPDATE `' . _DB_PREFIX_ . self::$definition['table'] . '` 
                SET ' . implode(', ', $sql_parts) . ' 
                WHERE `' . self::$definition['primary'] . '` = \'' . pSQL($this->id_payment) . '\'';

        return \Db::getInstance()->execute($sql);
    }

    /**
     * Override delete to handle string primary key
     */
    public function delete()
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . self::$definition['table'] . '` 
                WHERE `' . self::$definition['primary'] . '` = \'' . pSQL($this->id_payment) . '\'';

        return \Db::getInstance()->execute($sql);
    }

    /**
     * Override save to properly handle add/update
     */
    public function save($null_values = false, $auto_date = true)
    {
        // Check if record exists
        $sql = 'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . self::$definition['table'] . '` 
                WHERE `' . self::$definition['primary'] . '` = \'' . pSQL($this->id_payment) . '\'';

        $exists = (bool) \Db::getInstance()->getValue($sql);

        if ($exists) {
            return $this->update($null_values);
        } else {
            return $this->add($auto_date, $null_values);
        }
    }

    /**
     * Static finder methods to replace repository pattern
     */
    public static function getByIdOrder($id_order)
    {
        $sql = 'SELECT `id_payment` FROM `' . _DB_PREFIX_ . 'monei2_payment` 
                WHERE `id_order` = ' . (int) $id_order;
        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    public static function getByIdCart($id_cart)
    {
        $sql = 'SELECT `id_payment` FROM `' . _DB_PREFIX_ . 'monei2_payment` 
                WHERE `id_cart` = ' . (int) $id_cart;
        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    public static function getByIdOrderMonei($id_order_monei)
    {
        $sql = 'SELECT `id_payment` FROM `' . _DB_PREFIX_ . 'monei2_payment` 
                WHERE `id_order_monei` = \'' . pSQL($id_order_monei) . '\'';
        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    public static function findOneBy($criteria)
    {
        $where_parts = [];
        foreach ($criteria as $field => $value) {
            if (is_int($value)) {
                $where_parts[] = '`' . pSQL($field) . '` = ' . (int) $value;
            } else {
                $where_parts[] = '`' . pSQL($field) . '` = \'' . pSQL($value) . '\'';
            }
        }

        $sql = 'SELECT `id_payment` FROM `' . _DB_PREFIX_ . 'monei2_payment` 
                WHERE ' . implode(' AND ', $where_parts);

        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    /**
     * Compatibility methods for existing code
     */
    public function isRefundable()
    {
        $amount = $this->getAmount();
        if ($amount === null) {
            return false;
        }

        $refundedAmount = $this->getRefundedAmount() ?? 0;
        if ($refundedAmount < $amount) {
            return true;
        }

        return false;
    }

    public function getRemainingAmountToRefund()
    {
        $amount = $this->getAmount() ?? 0;
        $refundedAmount = $this->getRefundedAmount() ?? 0;

        return $amount - $refundedAmount;
    }

    public function getId()
    {
        return $this->id_payment;
    }

    public function setId($id)
    {
        $this->id_payment = $id;
        $this->id = $id;

        return $this;
    }

    public function getCartId()
    {
        return $this->id_cart;
    }

    public function setCartId($id_cart)
    {
        $this->id_cart = $id_cart;

        return $this;
    }

    public function getOrderId()
    {
        return $this->id_order;
    }

    public function setOrderId($id_order)
    {
        $this->id_order = $id_order;

        return $this;
    }

    public function getOrderMoneiId()
    {
        return $this->id_order_monei;
    }

    public function setOrderMoneiId($id_order_monei)
    {
        $this->id_order_monei = $id_order_monei;

        return $this;
    }

    /**
     * @return int|float|null Returns int when $inDecimal is false, float when true, null if amount not set
     */
    public function getAmount($inDecimal = false)
    {
        if ($this->amount === null) {
            return null;
        }

        return $inDecimal ? $this->amount / 100 : $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    public function getRefundedAmount($inDecimal = false)
    {
        return $inDecimal && $this->refunded_amount !== null ? $this->refunded_amount / 100 : $this->refunded_amount;
    }

    public function setRefundedAmount($refunded_amount)
    {
        $this->refunded_amount = $refunded_amount;

        return $this;
    }

    public function getCurrency()
    {
        return $this->currency;
    }

    public function setCurrency($currency)
    {
        $this->currency = $currency;

        return $this;
    }

    public function getAuthorizationCode()
    {
        return $this->authorization_code;
    }

    public function setAuthorizationCode($authorization_code)
    {
        $this->authorization_code = $authorization_code;

        return $this;
    }

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;

        return $this;
    }

    public function getIsCaptured()
    {
        return (bool) $this->is_captured;
    }

    public function setIsCaptured($is_captured)
    {
        $this->is_captured = (bool) $is_captured;

        return $this;
    }

    public function getStatusCode()
    {
        return $this->status_code;
    }

    public function setStatusCode($status_code)
    {
        $this->status_code = $status_code;

        return $this;
    }

    public function getDateAdd()
    {
        return $this->date_add ? new \DateTime($this->date_add) : null;
    }

    public function getDateAddFormatted()
    {
        return $this->date_add;
    }

    public function setDateAdd($timestamp)
    {
        if (is_int($timestamp)) {
            $this->date_add = date('Y-m-d H:i:s', $timestamp);
        } else {
            $this->date_add = $timestamp;
        }

        return $this;
    }

    public function getDateUpd()
    {
        return $this->date_upd ? new \DateTime($this->date_upd) : null;
    }

    public function getDateUpdFormatted()
    {
        return $this->date_upd;
    }

    public function setDateUpd($timestamp)
    {
        if (is_int($timestamp)) {
            $this->date_upd = date('Y-m-d H:i:s', $timestamp);
        } else {
            $this->date_upd = $timestamp;
        }

        return $this;
    }

    /**
     * Get history list - returns array instead of Collection
     */
    public function getHistoryList()
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'monei2_history` 
                WHERE `id_payment` = \'' . pSQL($this->id_payment) . '\' 
                ORDER BY `date_add` DESC';

        $results = \Db::getInstance()->executeS($sql);
        $history = [];

        if ($results) {
            foreach ($results as $row) {
                $historyItem = new Monei2History();
                $historyItem->hydrate($row);
                $history[] = $historyItem;
            }
        }

        return $history;
    }

    /**
     * Add history
     */
    public function addHistory($paymentHistory)
    {
        $paymentHistory->setPayment($this);

        return $paymentHistory->save();
    }

    /**
     * Get refund list - returns array instead of Collection
     */
    public function getRefundList()
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE `id_payment` = \'' . pSQL($this->id_payment) . '\' 
                ORDER BY `date_add` DESC';

        $results = \Db::getInstance()->executeS($sql);
        $refunds = [];

        if ($results) {
            foreach ($results as $row) {
                $refundItem = new Monei2Refund();
                $refundItem->hydrate($row);
                $refunds[] = $refundItem;
            }
        }

        return $refunds;
    }

    /**
     * Get refund by history ID
     */
    public function getRefundByHistoryId($historyId)
    {
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE `id_payment` = \'' . pSQL($this->id_payment) . '\' 
                AND `id_history` = ' . (int) $historyId;

        $row = \Db::getInstance()->getRow($sql);

        if ($row) {
            $refund = new Monei2Refund();
            $refund->hydrate($row);

            return $refund;
        }

        return null;
    }

    /**
     * Add refund
     */
    public function addRefund($paymentRefund)
    {
        $paymentRefund->setPayment($this);

        return $paymentRefund->save();
    }

    public function toArray()
    {
        return [
            'id' => $this->getId(),
            'cartId' => $this->getCartId(),
            'orderId' => $this->getOrderId(),
            'orderMoneiId' => $this->getOrderMoneiId(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'authorizationCode' => $this->getAuthorizationCode(),
            'status' => $this->getStatus(),
            'dateAdd' => $this->getDateAddFormatted(),
            'dateUpd' => $this->getDateUpdFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'id_payment' => $this->getId(),
            'id_cart' => $this->getCartId(),
            'id_order' => $this->getOrderId(),
            'id_order_monei' => $this->getOrderMoneiId(),
            'amount' => $this->getAmount(),
            'currency' => $this->getCurrency(),
            'authorization_code' => $this->getAuthorizationCode(),
            'status' => $this->getStatus(),
            'date_add' => $this->getDateAddFormatted(),
            'date_upd' => $this->getDateUpdFormatted(),
        ];
    }
}
