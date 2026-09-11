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
 *
 * ⚠️ Creating the cart and switching the session to it are two separate steps on
 * purpose. The cart has to exist while the button is merely on screen, because
 * the wallet sheet needs an exact total and only a real cart can price shipping.
 * But the session must not move until the wallet approves: a mount happens on
 * every product page view, for every configured method, and switching the
 * session there swapped the shopper's basket for a one-item cart just for
 * looking at a product — and with several methods, overwrote the record of
 * which cart to return to.
 */
class ExpressCartService
{
    /**
     * Session key holding the cart to return to.
     */
    public const PREVIOUS_CART_KEY = 'monei_express_previous_cart';

    /**
     * Create an express cart holding a single product.
     *
     * Does not touch the session. The cart belongs to the current guest or
     * customer, which is what lets adopt() check it later.
     *
     * @param \Context $context Shop context
     * @param int $productId Product to buy
     * @param int $productAttributeId Combination, 0 when the product has none
     * @param int $quantity Quantity
     *
     * @return \Cart The express cart, not yet the current one
     *
     * @throws \PrestaShopException when the product cannot be added
     */
    public function create(\Context $context, int $productId, int $productAttributeId, int $quantity): \Cart
    {
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
            $cart->delete();

            throw new \PrestaShopException('The product could not be added to the express cart');
        }

        return $cart;
    }

    /**
     * Make an express cart the session's current cart, remembering the old one.
     *
     * ⚠️ The id arrives from the browser, so ownership is checked here rather than
     * trusted: a cart created by another guest or customer is refused. Without
     * this, any visitor could pay for — and thereby learn the contents of —
     * someone else's cart by guessing its id.
     *
     * @param \Context $context Shop context
     * @param int $cartId Express cart previously returned by create()
     *
     * @return \Cart The express cart, now the current one
     *
     * @throws \PrestaShopException when the cart is missing or not the caller's
     */
    public function adopt(\Context $context, int $cartId): \Cart
    {
        $cart = new \Cart($cartId);

        if (!\Validate::isLoadedObject($cart) || !$this->isOwnedBySession($context, $cart)) {
            throw new \PrestaShopException('The express cart is not available');
        }

        if ((int) $context->cookie->id_cart !== (int) $cart->id) {
            // Remember where to go back to before switching, so a failure part
            // way through still has somewhere to return to.
            $context->cookie->{self::PREVIOUS_CART_KEY} = (int) $context->cookie->id_cart;
        }

        $context->cookie->id_cart = (int) $cart->id;
        $context->cart = $cart;
        $context->cookie->write();

        return $cart;
    }

    /**
     * Delete an express cart the shopper no longer needs.
     *
     * Ownership is checked for the same reason as in adopt(). The current session
     * cart is never deleted, whatever id is passed.
     *
     * @param \Context $context Shop context
     * @param int $cartId Express cart previously returned by create()
     */
    public function discard(\Context $context, int $cartId): void
    {
        if ($cartId === (int) $context->cookie->id_cart) {
            return;
        }

        $cart = new \Cart($cartId);

        if (\Validate::isLoadedObject($cart) && $this->isOwnedBySession($context, $cart)) {
            $cart->delete();
        }
    }

    /**
     * Whether the session that created a cart is the one asking for it.
     */
    private function isOwnedBySession(\Context $context, \Cart $cart): bool
    {
        $customerId = (int) $context->customer->id;

        if ($customerId > 0) {
            return (int) $cart->id_customer === $customerId;
        }

        $guestId = (int) $context->cookie->id_guest;

        return $guestId > 0 && (int) $cart->id_guest === $guestId;
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
