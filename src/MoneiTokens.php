<?php
namespace PsMonei;

use ObjectModel;

class MoneiTokens extends ObjectModel
{
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

    public static $definition = array(
        'table' => 'monei_tokens',
        'primary' => 'id_monei_tokens',
        'fields' => array(
            'id_customer' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'brand' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50),
            'country' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 4),
            'last_four' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'required' => true, 'size' => 20),
            'threeDS' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'threeDS_version' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'size' => 50),
            'expiration' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'tokenized' => array('type' => self::TYPE_STRING, 'validate' => 'isString', 'required' => true, 'size' => 255),
            'date_add' => array('type' => self::TYPE_DATE),
        ),
    );
}