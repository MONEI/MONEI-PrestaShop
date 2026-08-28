<?php

declare(strict_types=1);

namespace PsMonei\Service\Express;

/**
 * Keeps a shopper's cart safe while a product page express payment borrows it.
 *
 * Express checkout from a product page has to charge for that one product, but the
 * shopper may already have a cart. Emptying it and rebuilding it afterwards is how
 * the WooCommerce plugin has to work, because WooCommerce has exactly one cart.
 *
 * PrestaShop does not have that constraint: a customer can own several carts, and
 * the context simply points at one. So express gets a cart of its own and the
 * context is pointed back afterwards. Nothing the shopper collected is ever
 * deleted, which removes a whole category of "my basket disappeared" failure —
 * including the case where the browser is closed mid payment and no restore code
 * ever runs.
 */
class ExpressCartService
{
    /**
     * Session key holding the cart to return to.
     */
    public const PREVIOUS_CART_KEY = 'monei_express_previous_cart';

    /**
     * Start an express cart holding a single product.
     *
     * @param \Context $context Shop context
     * @param int $productId Product to buy
     * @param int $productAttributeId Combination, 0 when the product has none
     * @param int $quantity Quantity
     *
     * @return \Cart The express cart, now the current one
     *
     * @throws \PrestaShopException when the product cannot be added
     */
    public function start(\Context $context, int $productId, int $productAttributeId, int $quantity): \Cart
    {
        $previous = (int) $context->cookie->id_cart;

        $cart = new \Cart();
        $cart->id_currency = (int) $context->currency->id;
        $cart->id_lang = (int) $context->language->id;
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->id_customer = (int) $context->customer->id;
        $cart->id_guest = (int) $context->cookie->id_guest;
        $cart->id_address_delivery = (int) $context->cart->id_address_delivery;
        $cart->id_address_invoice = (int) $context->cart->id_address_invoice;
        $cart->secure_key = $context->customer->secure_key;
        $cart->add();

        $added = $cart->updateQty($quantity, $productId, $productAttributeId ?: null);

        if ($added !== true) {
            // Leave the shopper where they were rather than stranding them on an
            // empty express cart.
            $cart->delete();

            throw new \PrestaShopException('The product could not be added to the express cart');
        }

        // Remember where to go back to before switching, so a failure part way
        // through still has somewhere to return to.
        $context->cookie->{self::PREVIOUS_CART_KEY} = $previous;
        $context->cookie->id_cart = (int) $cart->id;
        $context->cart = $cart;
        $context->cookie->write();

        return $cart;
    }

    /**
     * Point the shopper back at the cart they had.
     *
     * Safe to call more than once, and safe to call when express never started —
     * every exit path from the flow calls it, including the failure paths.
     *
     * @param \Context $context Shop context
     */
    public function restore(\Context $context): void
    {
        $previous = (int) ($context->cookie->{self::PREVIOUS_CART_KEY} ?? 0);

        if (!$previous) {
            return;
        }

        $cart = new \Cart($previous);

        if (\Validate::isLoadedObject($cart)) {
            $context->cookie->id_cart = (int) $cart->id;
            $context->cart = $cart;
        }

        unset($context->cookie->{self::PREVIOUS_CART_KEY});
        $context->cookie->write();
    }

    /**
     * Is an express cart currently borrowing the context?
     *
     * @param \Context $context Shop context
     */
    public function isActive(\Context $context): bool
    {
        return (int) ($context->cookie->{self::PREVIOUS_CART_KEY} ?? 0) > 0;
    }
}
