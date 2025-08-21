<?php

namespace PsMonei\Entity;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei2Refund extends \ObjectModel
{
    public $id_refund;
    public $id_payment;
    public $id_history;
    public $id_employee;
    public $reason = 'requested_by_customer';
    public $amount;
    public $date_add;

    /**
     * @var array Object model definition
     */
    public static $definition = [
        'table' => 'monei2_refund',
        'primary' => 'id_refund',
        'fields' => [
            'id_payment' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'required' => true],
            'id_history' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'id_employee' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId'],
            'reason' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50],
            'amount' => ['type' => self::TYPE_INT, 'validate' => 'isInt'],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * References to related objects
     */
    private $payment;
    private $history;

    /**
     * Static finder methods to replace repository pattern
     */
    public static function getByPaymentId($id_payment)
    {
        $sql = 'SELECT `id_refund` FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE `id_payment` = \'' . pSQL($id_payment) . '\' 
                ORDER BY `date_add` DESC';
        
        $results = \Db::getInstance()->executeS($sql);
        $refunds = [];
        
        if ($results) {
            foreach ($results as $row) {
                $refunds[] = new self($row['id_refund']);
            }
        }
        
        return $refunds;
    }

    public static function getByHistoryId($id_history)
    {
        $sql = 'SELECT `id_refund` FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE `id_history` = ' . (int)$id_history;
        
        $id = \Db::getInstance()->getValue($sql);
        return $id ? new self($id) : null;
    }

    public static function findBy($criteria)
    {
        $where_parts = [];
        foreach ($criteria as $field => $value) {
            if (is_int($value)) {
                $where_parts[] = '`' . pSQL($field) . '` = ' . (int)$value;
            } else {
                $where_parts[] = '`' . pSQL($field) . '` = \'' . pSQL($value) . '\'';
            }
        }
        
        $sql = 'SELECT `id_refund` FROM `' . _DB_PREFIX_ . 'monei2_refund`';
        if (!empty($where_parts)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }
        $sql .= ' ORDER BY `date_add` DESC';
        
        $results = \Db::getInstance()->executeS($sql);
        $refunds = [];
        
        if ($results) {
            foreach ($results as $row) {
                $refunds[] = new self($row['id_refund']);
            }
        }
        
        return $refunds;
    }

    public static function findOneBy($criteria)
    {
        $where_parts = [];
        foreach ($criteria as $field => $value) {
            if (is_int($value)) {
                $where_parts[] = '`' . pSQL($field) . '` = ' . (int)$value;
            } else {
                $where_parts[] = '`' . pSQL($field) . '` = \'' . pSQL($value) . '\'';
            }
        }
        
        $sql = 'SELECT `id_refund` FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE ' . implode(' AND ', $where_parts) . ' 
                LIMIT 1';
        
        $id = \Db::getInstance()->getValue($sql);
        return $id ? new self($id) : null;
    }

    /**
     * Compatibility methods for existing code
     */
    public function getId()
    {
        return (int)$this->id_refund;
    }

    public function getPayment()
    {
        if (!$this->payment && $this->id_payment) {
            $this->payment = new Monei2Payment($this->id_payment);
        }
        return $this->payment;
    }

    public function setPayment($payment)
    {
        $this->payment = $payment;
        if ($payment instanceof Monei2Payment) {
            $this->id_payment = $payment->getId();
        } else if (is_string($payment)) {
            $this->id_payment = $payment;
        }
        return $this;
    }

    public function getHistory()
    {
        if (!$this->history && $this->id_history) {
            $this->history = new Monei2History($this->id_history);
        }
        return $this->history;
    }

    public function setHistory($history)
    {
        $this->history = $history;
        if ($history instanceof Monei2History) {
            $this->id_history = $history->getId();
        } else if (is_int($history)) {
            $this->id_history = $history;
        }
        return $this;
    }

    public function getEmployeeId()
    {
        return $this->id_employee;
    }

    public function setEmployeeId($id_employee)
    {
        $this->id_employee = $id_employee;
        return $this;
    }

    public function getReason()
    {
        return $this->reason;
    }

    public function setReason($reason)
    {
        $this->reason = $reason;
        return $this;
    }

    public function getAmount($inDecimal = false)
    {
        return $inDecimal && $this->amount !== null ? $this->amount / 100 : $this->amount;
    }

    public function setAmount($amount)
    {
        $this->amount = $amount;
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

    public function setDateAdd($date_add)
    {
        if ($date_add instanceof \DateTimeInterface) {
            $this->date_add = $date_add->format('Y-m-d H:i:s');
        } else if (is_int($date_add)) {
            $this->date_add = date('Y-m-d H:i:s', $date_add);
        } else {
            $this->date_add = $date_add;
        }
        return $this;
    }

    public function toArray()
    {
        return [
            'idEmployee' => $this->getEmployeeId(),
            'reason' => $this->getReason(),
            'amount' => $this->getAmount(),
            'amountInDecimal' => $this->getAmount(true),
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'id_employee' => $this->getEmployeeId(),
            'reason' => $this->getReason(),
            'amount' => $this->getAmount(),
            'amount_in_decimal' => $this->getAmount(true),
            'date_add' => $this->getDateAddFormatted(),
        ];
    }
}