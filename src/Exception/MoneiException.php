<?php
namespace PsMonei\Exception;

use Exception;

class MoneiException extends Exception
{
    const CART_NOT_FOUND = 1;
    const CART_AMOUNT_EMPTY = 2;
    const CURRENCY_NOT_FOUND = 3;
    const CUSTOMER_NOT_FOUND = 4;
    const ADDRESS_NOT_FOUND = 5;
    const MONEI_API_KEY_IS_EMPTY = 6;
    const MONEI_CLIENT_NOT_INITIALIZED = 7;
    const PAYMENT_REQUEST_NOT_VALID = 8;
    const ORDER_NOT_FOUND = 9;
}
