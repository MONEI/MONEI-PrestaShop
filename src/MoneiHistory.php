<?php
namespace PsMonei;

use ObjectModel;

class MoneiHistory extends ObjectModel
{
    public $id_monei_history;
    public $id_monei;
    public $status;
    public $id_monei_code;
    public $is_refund;
    public $is_callback;
    public $response;
    public $date_add;

    public static $definition = array(
        'table' => 'monei_history',
        'primary' => 'id_monei_history',
        'fields' => array(
            'id_monei' => array('type' => self::TYPE_INT, 'validate' => 'isunsignedInt', 'required' => true),
            'status' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'size' => 20),
            'is_refund' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'is_callback' => array('type' => self::TYPE_BOOL, 'validate' => 'isBool'),
            'response' => array('type' => self::TYPE_STRING, 'validate' => 'isLabel', 'size' => 4000),
            'date_add' => array('type' => self::TYPE_DATE),
        ),
    );
}