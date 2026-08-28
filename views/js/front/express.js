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
    const prepareCart = async (container) => {
        if (container.dataset.location !== 'product') {
            return request('getCartDetails');
        }

        return request('addToCart', {
            productId: parseInt(container.dataset.productId || '0', 10) || 0,
            productAttributeId: selectedCombination(),
            quantity: selectedQuantity(),
        });
    };

    /**
     * Take the payment once the wallet has authorised it.
     *
     * @param {HTMLElement} container Express container
     * @param {Object}      result    Wallet result carrying the token
     * @param {string}      method    Express method that started this
     */
    const completePayment = async (container, result, method) => {
        const order = await request('createOrder', {
            paymentMethod: method,
            email: result.paymentMethod && result.paymentMethod.email,
            shippingAddress: result.shippingAddress || result.billingAddress || {},
            amount: container.dataset.amount ? parseInt(container.dataset.amount, 10) : null,
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
    const handlers = (container, method) => ({
        onBeforeOpen: () => {
            clearError(container);

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

        // Remembered so createOrder can be checked against what the sheet showed.
        container.dataset.amount = String(cart.amount);

        const common = {
            accountId: moneiExpress.accountId,
            amount: cart.amount,
            currency: cart.currency,
            ...handlers(container, method),
        };

        if (method === 'paypal') {
            monei
                .PayPal({
                    ...common,
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
