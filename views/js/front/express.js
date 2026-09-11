/**
 * Express checkout client.
 *
 * Mounts an Apple Pay, Google Pay or PayPal button on the product, cart or
 * checkout page and drives the whole payment from there.
 *
 * ⚠️ Every failure is reported on the container the payment started from. This is
 * the single most important rule in this file. The worst bug of the WooCommerce
 * round was a rejected express order being discarded in silence: the platform did
 * not consider the express method "active", so nothing owned the failure, and the
 * shopper sat looking at a page that had already taken their wallet approval.
 * Ownership follows "did this button start the payment", never any platform notion
 * of a currently selected payment method.
 */
(function () {
    if (typeof moneiExpress === 'undefined' || typeof monei === 'undefined') {
        return;
    }

    /**
     * Call an express endpoint.
     *
     * @param {string} action  Endpoint action
     * @param {Object} payload Extra body fields
     * @return {Promise<Object>} Parsed response
     */
    const request = async (action, payload = {}) => {
        const response = await fetch(moneiExpress.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ action, token: moneiExpress.token, ...payload }),
        });

        let data;

        try {
            data = await response.json();
        } catch (error) {
            // A non JSON body means the request never reached the controller —
            // a proxy error page, a PHP fatal. Treated as a failure below.
            data = null;
        }

        if (!response.ok || !data || data.ok !== true) {
            // Carries the server's own message where there is one: "the amount
            // changed while the wallet was open" is worth showing verbatim.
            throw new Error((data && data.message) || moneiExpress.errorGeneric);
        }

        return data;
    };

    /**
     * Show a failure on the container that started the payment.
     *
     * @param {HTMLElement} container Express container
     * @param {string}      message   What to tell the shopper
     */
    const showError = (container, message) => {
        const region = container.querySelector('[data-monei-express-error]');

        if (region) {
            region.textContent = message || moneiExpress.errorGeneric;
            region.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    };

    const clearError = (container) => {
        const region = container.querySelector('[data-monei-express-error]');

        if (region) {
            region.textContent = '';
        }
    };

    /**
     * Quantity the shopper chose, on a product page.
     *
     * @return {number} Quantity, at least 1
     */
    const selectedQuantity = () => {
        const input = document.querySelector('input[name="qty"], #quantity_wanted');

        return Math.max(1, parseInt((input && input.value) || '1', 10) || 1);
    };

    /**
     * Combination the shopper chose, on a product page.
     *
     * @return {number} Combination id, 0 when the product has none
     */
    const selectedCombination = () => {
        const input = document.querySelector('input[name="id_product_attribute"]');

        return parseInt((input && input.value) || '0', 10) || 0;
    };

    /**
     * Put the express product in a cart of its own, on a product page.
     *
     * On the cart and checkout pages the shopper's cart is already the thing being
     * paid for, so nothing is created.
     *
     * @param {HTMLElement} container Express container
     * @return {Promise<Object>} Cart details
     */
    /**
     * Price what the button will charge for.
     *
     * On a product page this creates an express cart, because only a real cart can
     * price shipping and the wallet sheet must show the exact total. It does NOT
     * become the session cart: that happens in completePayment, once the wallet
     * has approved. Viewing a product page therefore never touches the shopper's
     * own basket.
     *
     * One cart per container, shared by every method slot in it. The request is
     * memoised so three buttons do not create three carts.
     *
     * @param {HTMLElement} container Express container
     * @return {Promise<object>} Cart payload from the server
     */
    const prepareCart = (container) => {
        if (container.dataset.location !== 'product') {
            return request('getCartDetails');
        }

        if (!container.moneiCartPromise) {
            container.moneiCartPromise = request('addToCart', {
                productId: parseInt(container.dataset.productId || '0', 10) || 0,
                productAttributeId: selectedCombination(),
                quantity: selectedQuantity(),
                // A previous cart for this container is deleted server side, so
                // changing quantity does not leave one abandoned cart per change.
                replacesCartId: parseInt(container.dataset.expressCartId || '0', 10) || 0,
            }).then((cart) => {
                container.dataset.expressCartId = String(cart.expressCartId || '');

                return cart;
            });
        }

        return container.moneiCartPromise;
    };

    /**
     * Take the payment once the wallet has authorised it.
     *
     * @param {HTMLElement} container Express container
     * @param {Object}      result    Wallet result carrying the token
     * @param {string}      method    Express method that started this
     */
    const completePayment = async (container, result, method) => {
        // ⚠️ monei.js v3 reports the contact as billingDetails / shippingDetails,
        // each { name, email, phone, address: { line1, line2, city, zip, state,
        // country } }, and paymentMethod is the wallet that was used — a string.
        // The first version of this read result.shippingAddress and
        // result.paymentMethod.email, neither of which exists, so every real
        // wallet payment for a physical cart failed after approval. Pass the
        // SDK's own shapes through; the server normalises them.
        const shipping = result.shippingDetails || null;
        const billing = result.billingDetails || null;

        const order = await request('createOrder', {
            // A PaymentRequest slot serves Apple Pay and Google Pay alike; the
            // result says which one the shopper actually used.
            paymentMethod: result.paymentMethod || method,
            email: (shipping && shipping.email) || (billing && billing.email) || '',
            shippingDetails: shipping || billing || {},
            billingDetails: billing || {},
            // Present only for product page express. The server adopts this cart
            // as the session cart now — the first moment the shopper's own basket
            // is set aside — and restores the basket if anything fails after.
            expressCartId: parseInt(container.dataset.expressCartId || '0', 10) || 0,
        });

        const confirmed = await monei.confirmPayment({
            paymentId: order.paymentId,
            paymentToken: result.token,
        });

        if (confirmed.nextAction && confirmed.nextAction.redirectUrl) {
            location.assign(confirmed.nextAction.redirectUrl);

            return;
        }

        throw new Error(confirmed.statusMessage || moneiExpress.errorGeneric);
    };

    /**
     * Shared handlers for every express component.
     *
     * @param {HTMLElement} container Express container
     * @param {string}      method    Express method
     * @return {Object} Component callbacks
     */
    /**
     * Whether every required checkbox under the checkout's terms block is ticked.
     *
     * The ordinary MONEI components gate on this; the express buttons at the
     * checkout location have to as well, or a wallet can complete a payment
     * with the terms unaccepted. Elsewhere there is no such block and this is
     * trivially true.
     */
    const conditionsAccepted = () => {
        const block = document.getElementById('conditions-to-approve');

        if (!block) {
            return true;
        }

        return Array.from(block.querySelectorAll('input[type="checkbox"][required]')).every(
            (box) => box.checked
        );
    };

    const handlers = (container, method) => ({
        onBeforeOpen: () => {
            clearError(container);

            if (container.dataset.location === 'checkout' && !conditionsAccepted()) {
                showError(container, moneiExpress.errorTerms);

                return false;
            }

            return true;
        },
        onSubmit: async (result) => {
            try {
                if (!result || !result.token) {
                    throw new Error(moneiExpress.errorGeneric);
                }

                await completePayment(container, result, method);
            } catch (error) {
                // ⚠️ The catch that matters. Anything thrown between the wallet
                // approving and the shopper being redirected lands here, and has
                // to become something visible on this container.
                showError(container, error.message);

                // createOrder may already have adopted the express cart before
                // confirmPayment was rejected. The server saw no failure, so it
                // is asked to put the shopper's own basket back.
                request('restoreCart').catch(() => {});
            }
        },
        onError: (error) => {
            showError(container, (error && error.message) || moneiExpress.errorGeneric);
        },
    });

    /**
     * Mount one express button.
     *
     * @param {HTMLElement} container Express container
     * @param {HTMLElement} slot      Element to render into
     * @param {string}      method    Express method
     */
    const mount = async (container, slot, method) => {
        const cart = await prepareCart(container);

        container.dataset.amount = String(cart.amount);

        const common = {
            accountId: moneiExpress.accountId,
            amount: cart.amount,
            currency: cart.currency,
            // Asks the wallet to collect a delivery address for a physical cart.
            //
            // The sheet total already carries shipping: Cart::getSummaryDetails
            // prices the cart's current carrier before any address exists, and
            // measured runs match what is charged (1912 -> 1912 free carrier,
            // 2612 -> 2612 paid). What it cannot yet reflect is a change of
            // carrier zone: the address arrives with the shopper's approval, and
            // the cheapest option for that zone is picked server side afterwards.
            //
            // monei.js v3 does expose onShippingAddressChange/onShippingOptionChange
            // on PaymentRequest, so this is closable — see PaymentRequestProps in
            // @monei-js/components. Not wired yet.
            requestShipping: Boolean(cart.shippingRequired),
            ...handlers(container, method),
        };

        if (method === 'paypal') {
            monei
                .PayPal({
                    ...common,
                    // Without this PayPal opens with its default SALE intent while
                    // the server creates an AUTH payment when pre-authorisation
                    // is configured, and the two disagree at confirmation.
                    transactionType: moneiExpress.paymentAction === 'auth' ? 'AUTH' : 'SALE',
                    style: moneiExpress.paypalStyle || {},
                })
                .render(slot);

            return;
        }

        monei
            .PaymentRequest({
                ...common,
                style: moneiExpress.style || {},
            })
            .render(slot);
    };

    const init = () => {
        document.querySelectorAll('[data-monei-express]').forEach((container) => {
            if (container.dataset.moneiMounted === '1') {
                return;
            }

            container.dataset.moneiMounted = '1';

            container.querySelectorAll('[data-monei-express-method]').forEach((slot) => {
                const method = slot.dataset.moneiExpressMethod;

                mount(container, slot, method).catch((error) => {
                    // A button that cannot even mount must say so rather than
                    // leaving an empty gap the shopper will wait on.
                    showError(container, error.message);
                });
            });
        });
    };

    /**
     * Rebuild the product page buttons after a quantity or combination change.
     *
     * The wallet sheet's amount is fixed when a button mounts, so a shopper who
     * changes the quantity after the page loads would otherwise approve the old
     * total. PrestaShop announces the change on its event bus; each container is
     * emptied and mounted again, which prices a fresh express cart.
     */
    const remountProductButtons = () => {
        document
            .querySelectorAll('[data-monei-express][data-location="product"]')
            .forEach((container) => {
                delete container.moneiCartPromise;
                container.dataset.moneiMounted = '';

                container.querySelectorAll('[data-monei-express-method]').forEach((slot) => {
                    slot.innerHTML = '';
                });
            });

        init();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    /**
     * Rebuild the cart page buttons after an AJAX cart change.
     *
     * The theme re-renders the cart block, including the express container,
     * without a page load. The new container has no mounted buttons, and if the
     * theme keeps the old one its amount is stale. Either way, mount again.
     */
    const remountCartButtons = () => {
        document
            .querySelectorAll('[data-monei-express][data-location="cart"]')
            .forEach((container) => {
                container.dataset.moneiMounted = '';
                container.querySelectorAll('[data-monei-express-method]').forEach((slot) => {
                    slot.innerHTML = '';
                });
            });

        init();
    };

    if (typeof prestashop !== 'undefined' && typeof prestashop.on === 'function') {
        prestashop.on('updatedProduct', remountProductButtons);
        prestashop.on('updatedCart', remountCartButtons);
    }
})();
