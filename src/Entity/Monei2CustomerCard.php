<?php

namespace PsMonei\Entity;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Monei2CustomerCard extends \ObjectModel
{
    public $id_customer_card;
    public $id_customer;
    public $brand;
    public $country;
    public $last_four;
    public $expiration;
    public $tokenized;
    public $date_add;

    /**
     * @var array Object model definition
     */
    public static $definition = [
        'table' => 'monei2_customer_card',
        'primary' => 'id_customer_card',
        'fields' => [
            'id_customer' => ['type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true],
            'brand' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50],
            'country' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4],
            'last_four' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 20, 'required' => true],
            'expiration' => ['type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true],
            'tokenized' => ['type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 255, 'required' => true],
            'date_add' => ['type' => self::TYPE_DATE, 'validate' => 'isDate'],
        ],
    ];

    /**
     * Static finder methods to replace repository pattern
     */
    public static function getByCustomer($id_customer)
    {
        $sql = 'SELECT `id_customer_card` FROM `' . _DB_PREFIX_ . 'monei2_customer_card` 
                WHERE `id_customer` = ' . (int) $id_customer . ' 
                ORDER BY `date_add` DESC';

        $results = \Db::getInstance()->executeS($sql);
        $cards = [];

        if ($results) {
            foreach ($results as $row) {
                $cards[] = new self($row['id_customer_card']);
            }
        }

        return $cards;
    }

    public static function getByCustomerAndId($id_customer, $id_customer_card)
    {
        $sql = 'SELECT `id_customer_card` FROM `' . _DB_PREFIX_ . 'monei2_customer_card` 
                WHERE `id_customer` = ' . (int) $id_customer . ' 
                AND `id_customer_card` = ' . (int) $id_customer_card;

        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    public static function findBy($criteria)
    {
        $where_parts = [];
        foreach ($criteria as $field => $value) {
            if (is_int($value)) {
                $where_parts[] = '`' . pSQL($field) . '` = ' . (int) $value;
            } else {
                $where_parts[] = '`' . pSQL($field) . '` = \'' . pSQL($value) . '\'';
            }
        }

        $sql = 'SELECT `id_customer_card` FROM `' . _DB_PREFIX_ . 'monei2_customer_card`';
        if (!empty($where_parts)) {
            $sql .= ' WHERE ' . implode(' AND ', $where_parts);
        }
        $sql .= ' ORDER BY `date_add` DESC';

        $results = \Db::getInstance()->executeS($sql);
        $cards = [];

        if ($results) {
            foreach ($results as $row) {
                $cards[] = new self($row['id_customer_card']);
            }
        }

        return $cards;
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

        $sql = 'SELECT `id_customer_card` FROM `' . _DB_PREFIX_ . 'monei2_customer_card` 
                WHERE ' . implode(' AND ', $where_parts);

        $id = \Db::getInstance()->getValue($sql);

        return $id ? new self($id) : null;
    }

    /**
     * Compatibility methods for existing code
     */
    public function getId()
    {
        return (int) $this->id_customer_card;
    }

    public function getCustomerId()
    {
        return (int) $this->id_customer;
    }

    public function setCustomerId($id_customer)
    {
        $this->id_customer = (int) $id_customer;

        return $this;
    }

    public function getBrand()
    {
        return $this->brand !== null ? strtoupper($this->brand) : null;
    }

    public function setBrand($brand)
    {
        $this->brand = $brand;

        return $this;
    }

    public function getCountry()
    {
        return $this->country;
    }

    public function setCountry($country)
    {
        $this->country = $country;

        return $this;
    }

    public function getLastFour()
    {
        return $this->last_four;
    }

    public function getLastFourWithMask()
    {
        return '•••• ' . $this->last_four;
    }

    public function setLastFour($last_four)
    {
        $this->last_four = $last_four;

        return $this;
    }

    public function getExpiration()
    {
        return (int) $this->expiration;
    }

    public function getExpirationFormatted()
    {
        return date('m/y', $this->expiration);
    }

    public function setExpiration($expiration)
    {
        $this->expiration = (int) $expiration;

        return $this;
    }

    public function getTokenized()
    {
        return $this->tokenized;
    }

    public function setTokenized($tokenized)
    {
        $this->tokenized = $tokenized;

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

    public function toArrayLegacy()
    {
        return [
            'id_customer_card' => $this->getId(),
            'id_customer' => $this->getCustomerId(),
            'brand' => $this->getBrand(),
            'country' => $this->getCountry(),
            'last_four' => $this->getLastFour(),
            'last_four_with_mask' => $this->getLastFourWithMask(),
            'expiration' => $this->getExpiration(),
            'tokenized' => $this->getTokenized(),
            'date_add' => $this->getDateAddFormatted(),
        ];
    }

    public function toArray()
    {
        return [
            'id' => $this->getId(),
            'customerId' => $this->getCustomerId(),
            'brand' => $this->getBrand(),
            'country' => $this->getCountry(),
            'lastFour' => $this->getLastFour(),
            'lastFourWithMask' => $this->getLastFourWithMask(),
            'expiration' => $this->getExpiration(),
            'tokenized' => $this->getTokenized(),
            'dateAdd' => $this->getDateAddFormatted(),
        ];
    }
}
