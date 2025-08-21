<?php

namespace PsMonei\Entity;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei2History extends \ObjectModel
{
    public $id_history;
    public $id_payment;
    public $status;
    public $status_code;
    public $response;
    public $date_add;

    /**
     * @var array Object model definition
     */
    public static $definition = [
        'table' => 'monei2_history',
        'primary' => 'id_history',
        'fields' => [
            'id_payment' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50, 'required' => true],
            'status' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 20, 'required' => true],
            'status_code' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4],
            'response' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4000],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Reference to payment object
     */
    private $payment;

    /**
     * Static finder methods to replace repository pattern
     */
    public static function getByPaymentId($id_payment)
    {
        $sql = 'SELECT `id_history` FROM `' . _DB_PREFIX_ . 'monei2_history` 
                WHERE `id_payment` = \'' . pSQL($id_payment) . '\' 
                ORDER BY `date_add` DESC';
        
        $results = \Db::getInstance()->executeS($sql);
        $history = [];
        
        if ($results) {
            foreach ($results as $row) {
                $history[] = new self($row['id_history']);
            }
        }
        
        return $history;
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
        
        $sql = 'SELECT `id_history` FROM `' . _DB_PREFIX_ . 'monei2_history`';
        if (!empty($where_parts)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }
        $sql .= ' ORDER BY `date_add` DESC';
        
        $results = \Db::getInstance()->executeS($sql);
        $history = [];
        
        if ($results) {
            foreach ($results as $row) {
                $history[] = new self($row['id_history']);
            }
        }
        
        return $history;
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
        
        $sql = 'SELECT `id_history` FROM `' . _DB_PREFIX_ . 'monei2_history` 
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
        return (int)$this->id_history;
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

    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus($status)
    {
        $this->status = $status;
        return $this;
    }

    public function getStatusCode()
    {
        return $this->status_code ?? '';
    }

    public function setStatusCode($status_code)
    {
        $this->status_code = $status_code;
        return $this;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getResponseDecoded()
    {
        return $this->response ? json_decode($this->response, true) : null;
    }

    public function setResponse($response)
    {
        $this->response = $response;
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
        if ($date_add instanceof \DateTime) {
            $this->date_add = $date_add->format('Y-m-d H:i:s');
        } else if (is_int($date_add)) {
            $this->date_add = date('Y-m-d H:i:s', $date_add);
        } else {
            $this->date_add = $date_add;
        }
        return $this;
    }

    public function getRefund()
    {
        // Check if there's a refund associated with this history
        $sql = 'SELECT * FROM `' . _DB_PREFIX_ . 'monei2_refund` 
                WHERE `id_history` = ' . (int)$this->id_history;
        
        $row = \Db::getInstance()->getRow($sql);
        
        if ($row) {
            $refund = new Monei2Refund();
            $refund->hydrate($row);
            return $refund;
        }
        
        return null;
    }

    public function toArray()
    {
        return [
            'status' => $this->getStatus(),
            'statusCode' => $this->getStatusCode(),
            'response' => $this->getResponse(),
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }

    public function toArrayLegacy()
    {
        return [
            'status' => $this->getStatus(),
            'status_code' => $this->getStatusCode(),
            'response' => $this->getResponse(),
            'date_add' => $this->getDateAddFormatted(),
        ];
    }
}