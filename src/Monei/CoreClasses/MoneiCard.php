<?php


namespace Monei\CoreClasses;

use Db;
use ObjectModel;
use function Monei\CoreClasses\pSQL;
use const Monei\CoreClasses\_DB_PREFIX_;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MoneiCard extends ObjectModel
{
    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'monei_tokens',
        'primary' => 'id_monei_tokens',
        'fields' => array(
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'brand' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel'),
            'country' => array('type' => self::TYPE_STRING, 'validate' => 'isLangIsoCode'),
            'last_four' => array('type' => self::TYPE_STRING),
            'threeDS' => array('type' => self::TYPE_STRING),
            'threeDS_version' => array('type' => self::TYPE_STRING),
            'expiration' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt'),
            'tokenized' => array('type' => self::TYPE_STRING),
            'date_add' => array('type' => self::TYPE_DATE),
            'date_upd' => array('type' => self::TYPE_DATE),
        ),
    );
    public $id_monei_tokens;
    public $id_customer;
    public $brand;
    public $country;
    public $last_four;
    public $threeDS;
    public $threeDS_version;
    public $expiration;
    public $tokenized;
    public $date_add;
    public $date_upd;

    /**
     * Returns de number of cards for a customer
     * @param int $id_customer
     * @return int
     */
    public static function getNbCards($id_customer)
    {
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'monei_tokens WHERE id_customer = ' . (int)$id_customer;
        return Db::getInstance()->getValue($sql);
    }

    /**
     * Checks if a card belongs to a given customer
     * @param mixed $id_monei_tokens
     * @param mixed $id_customer
     * @return bool
     */
    public static function belongsToCustomer($id_monei_tokens, $id_customer)
    {
        $sql = 'SELECT id_monei_tokens FROM ' . _DB_PREFIX_ . 'monei_tokens WHERE id_monei_tokens = '
            . (int)$id_monei_tokens . ' AND id_customer = ' . (int)$id_customer;
        $id_monei_tokens = (int)Db::getInstance()->getValue($sql);
        if ($id_monei_tokens > 0) {
            return true;
        }
        return false;
    }

    /**
     * Gets the full tokenized card list for a customer
     * @return array|false
     */
    public function getCustomerCards($with_expired = false)
    {
        return self::getStaticCustomerCards($this->id_customer, $with_expired);
    }

    public static function getStaticCustomerCards($id_customer, $with_expired = true)
    {
        $sql = 'SELECT * FROM ' . _DB_PREFIX_ . 'monei_tokens WHERE id_customer = ' . (int)$id_customer .
            ($with_expired ? '' : ' AND expiration > ' . pSQL(time()));
        return Db::getInstance()->executeS($sql);
    }

    /**
     * Converts UNIX Epoch to human readable date
     * @return string
     */
    public function unixEpochToExpirationDate()
    {
        return date('m/y', $this->expiration);
    }
}
