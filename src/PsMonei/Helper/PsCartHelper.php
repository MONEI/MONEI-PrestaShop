<?php
namespace PsMonei\Helper;

use Cart;
use Configuration;
use Context;
use Order;

class PsCartHelper
{
    /**
     * Get amount from Cart
     * @param mixed $id_cart
     * @return int
     * @throws PrestaShopException
     * @throws LocalizationException
     */
    public static function getTotalFromCart($id_cart)
    {
        $cart = new Cart($id_cart);
        $virtual_context = Context::getContext()->cloneContext();
        // Set the cart to the current context
        $virtual_context->cart = $cart;
        $total = $cart->getOrderTotal(true, Cart::BOTH);
        $amount = (int)number_format($total, 2, '', '');

        return $amount;
    }

    /**
     * Checks if the order exists and if it has already failed
     * @param mixed $id_cart
     * @return bool
     */
    public static function checkIfAlreadyFailed($id_cart)
    {
        // If orders arent converted to payments, then exit
        if (Configuration::get('MONEI_CART_TO_ORDER')) {
            $order = new Order((int)Order::getIdByCartId($id_cart));
            if ($order->id > 0 && $order->getCurrentState() == Configuration::get('MONEI_STATUS_FAILED')) {
                return true;
            }
            return false;
        }
        return false;
    }
}
