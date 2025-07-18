<?php

namespace PsMonei\Exception;

use Exception;

class OrderException extends Exception
{
    const MONEI_CLIENT_PAYMENTS_NOT_INITIALIZED = 200;
    const CART_NOT_VALID = 201;
    const CUSTOMER_NOT_VALID = 202;
    const ORDER_ALREADY_EXISTS = 203;
    const ORDER_NOT_FOUND = 204;
}
