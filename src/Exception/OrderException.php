<?php

namespace PsMonei\Exception;

use Exception;

class OrderException extends Exception
{
    const MONEI_CLIENT_NOT_INITIALIZED = 1;
    const MONEI_CLIENT_PAYMENTS_NOT_INITIALIZED = 2;
    const CART_NOT_VALID = 3;
    const CUSTOMER_NOT_VALID = 4;
    const ORDER_ALREADY_EXISTS = 5;
}
