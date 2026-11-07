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
 * purpose. The cart is created when the shopper opens a wallet, because only a
 * real cart can price shipping for the address the wallet reports; a button that
 * is merely on screen is priced from the product, so a page view writes nothing.
 * The session must not move until the wallet approves: the shopper can still
 * close the sheet, and a session switched at open swapped their basket for a
 * one-item cart.
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
     * @throws \PrestaShopException when the product is not for sale here, or cannot be added
     */
    public function create(\Context $context, int $productId, int $productAttributeId, int $quantity): \Cart
    {
        $this->assertPurchasable($context, $productId, $productAttributeId, $quantity);
        $this->ensureGuest($context);

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
     * Refuse a product that PrestaShop's own add to cart would refuse.
     *
     * The id comes from the browser, and Cart::updateQty() checks none of this.
     * Visibility is not checked: "nowhere" hides a product from listings, but it
     * stays for sale on its own URL. Stock is checked here too, because the button
     * is priced without a cart and nothing else would refuse it before the click.
     *
     * @param \Context $context Shop context
     * @param int $productId Product to buy
     * @param int $productAttributeId Combination, 0 when the product has none
     * @param int $quantity Quantity
     *
     * @return \Product The product, loaded in the context language
     *
     * @throws \PrestaShopException when the product is not for sale here
     */
    public function assertPurchasable(\Context $context, int $productId, int $productAttributeId, int $quantity): \Product
    {
        $product = new \Product($productId, false, (int) $context->language->id, (int) $context->shop->id);

        if (!\Validate::isLoadedObject($product)
            || !$product->active
            || !$product->available_for_order
            || \Configuration::isCatalogMode()
            || !$product->isAssociatedToShop((int) $context->shop->id)
            || !$product->checkAccess((int) $context->customer->id)
            // A wallet cannot collect an engraving or an upload.
            || \Product::getRequiredCustomizableFieldsStatic($productId)
        ) {
            throw new \PrestaShopException('The product is not available');
        }

        $minimum = $productAttributeId
            ? (int) \ProductAttribute::getAttributeMinimalQty($productAttributeId)
            : (int) $product->minimal_quantity;

        if ($quantity < max(1, $minimum)) {
            throw new \PrestaShopException('The quantity is below the minimum for this product');
        }

        if (!\Product::isAvailableWhenOutOfStock($product->out_of_stock)
            && \Product::getQuantity($productId, $productAttributeId ?: null) < $quantity) {
            throw new \PrestaShopException('There are not enough products in stock');
        }

        return $product;
    }

    /**
     * An express cart this session created.
     *
     * @param \Context $context Shop context
     * @param int $cartId Express cart previously returned by create()
     *
     * @throws \PrestaShopException when the cart is missing or not the caller's
     */
    public function owned(\Context $context, int $cartId): \Cart
    {
        $cart = new \Cart($cartId);

        if (!\Validate::isLoadedObject($cart) || !$this->isOwnedBySession($context, $cart)) {
            throw new \PrestaShopException('The express cart is not available');
        }

        return $cart;
    }

    /**
     * The combination to buy, from what the browser sent.
     *
     * The posted group[] values decide, as in PrestaShop's own CartController: they
     * are the form the shopper sees, where the id read from the theme's details
     * block can be stale or missing. A product with combinations is never added
     * without one (base price, product-level stock), so the default is the last
     * resort.
     *
     * @param int $productId Product
     * @param int $productAttributeId Combination the browser sent, 0 when none
     * @param array $groups Posted group[] values: attribute group id => attribute id
     *
     * @return int Combination id, 0 for a product without combinations
     */
    public function resolveCombination(int $productId, int $productAttributeId, array $groups): int
    {
        if (!\Combination::isFeatureActive() || !\Product::getProductAttributesIds($productId)) {
            return 0;
        }

        $attributes = array_values(array_filter(array_map('intval', $groups)));

        if ($attributes) {
            try {
                return (int) \Product::getIdProductAttributeByIdAttributes($productId, $attributes, true);
            } catch (\PrestaShopObjectNotFoundException $e) {
                // No combination matches even in part: fall through.
            }
        }

        $owner = (int) \Db::getInstance()->getValue(
            'SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product_attribute`'
            . ' WHERE `id_product_attribute` = ' . (int) $productAttributeId,
            false
        );

        if ($productAttributeId > 0 && $owner === $productId) {
            return $productAttributeId;
        }

        // As CartController: an in-stock combination when the shop does not sell
        // out of stock.
        $inStockOnly = (int) !\Product::isAvailableWhenOutOfStock(\StockAvailable::outOfStock($productId));

        return (int) (\Product::getDefaultAttribute($productId, $inStockOnly) ?: \Product::getDefaultAttribute($productId));
    }

    /**
     * Give a visitor without an account a guest id, the identity adopt() checks.
     *
     * ⚠️ PrestaShop assigns one only through the statsdata module; without it an
     * express cart has no owner and every guest payment is refused. Called only
     * when an express cart is created: a guest row on every page render would
     * bring back the table growth merchants avoid by turning statsdata off, and
     * would pre-empt statsdata's own first-visit tracking when it is on. The
     * client creates the cart when the shopper opens a wallet, never at mount, so
     * a page view mints no id and two containers cannot race to mint two.
     *
     * @param \Context $context Shop context
     */
    public function ensureGuest(\Context $context): void
    {
        if ((int) $context->customer->id || (int) $context->cookie->id_guest) {
            return;
        }

        \Guest::setNewGuest($context->cookie);
        $context->cookie->write();
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
        $cart = $this->owned($context, $cartId);

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
