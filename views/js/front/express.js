/**
 * Express checkout client.
 *
 * Mounts an Apple Pay, Google Pay or PayPal button on the product and cart
 * pages and drives the whole payment from there.
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

    // ⚠️ Read the product's own form only: hummingbird's related-product miniatures
    // are add-to-cart forms too, with their own quantity and combination.
    const PRODUCT_FORM = '#add-to-cart-or-refresh';

    // Combination PrestaShop reported in its last updatedProduct event.
    let eventCombination = 0;

    // Whether the shopper has changed the product on this page (quantity,
    // combination). Only then is a failure to price the button theirs to see.
    let shopperChangedProduct = false;

    // Last express cart per product, so a remount into a re-rendered container
    // (which carries no cart id) still replaces it instead of abandoning it.
    const lastCartByProduct = {};

    /**
     * The add-to-cart form the container belongs to.
     *
     * A quickview opens its own copy of the form over the page, and a page-wide
     * lookup reads the page behind it. ⚠️ Classic renders the quickview's hook in
     * the modal footer, outside that form, so the modal is searched before the page.
     *
     * @param {HTMLElement} container Express container
     * @return {HTMLElement|null} Form
     */
    const productForm = (container) => {
        const own = container.closest(PRODUCT_FORM);

        if (own) {
            return own;
        }

        const modal = container.closest('.modal, .quickview');

        return modal ? modal.querySelector(PRODUCT_FORM) : document.querySelector(PRODUCT_FORM);
    };

    /**
     * Whether the container is a quickview's, not the product page's own.
     *
     * @param {HTMLElement} container Express container
     * @return {boolean} True inside a quickview or other modal
     */
    const inQuickview = (container) => Boolean(container.closest('.modal, .quickview'));

    /**
     * Quantity the shopper chose, on a product page.
     *
     * @param {HTMLElement} container Express container
     * @return {number} Quantity, at least 1
     */
    const selectedQuantity = (container) => {
        const form = productForm(container);
        const input = form && form.querySelector('input[name="qty"]');

        return Math.max(1, parseInt((input && input.value) || '1', 10) || 1);
    };

    /**
     * The form's group[] choices, which the server resolves when no combination
     * id can be read.
     *
     * @param {HTMLElement} container Express container
     * @return {Object<string, number>} Attribute group id => attribute id
     */
    const selectedGroups = (container) => {
        const form = productForm(container);
        const groups = {};

        if (!form) {
            return groups;
        }

        form.querySelectorAll('[name^="group["]').forEach((field) => {
            const match = field.name.match(/^group\[(\d+)\]$/);
            const chosen =
                field.tagName === 'SELECT' || (field.type !== 'radio' && field.type !== 'checkbox')
                    ? true
                    : field.checked;

            if (match && chosen && field.value) {
                groups[match[1]] = parseInt(field.value, 10) || 0;
            }
        });

        return groups;
    };

    /**
     * Combination the shopper chose, on a product page.
     *
     * The form has no combination field: the theme picks it with group[] selects
     * and reports the result in its product details block, re-rendered on every
     * change, and in the updatedProduct event. Not every theme renders either, so
     * 0 here is resolved from the group[] choices server side.
     *
     * @param {HTMLElement} container Express container
     * @return {number} Combination id, 0 when it cannot be read
     */
    const selectedCombination = (container) => {
        // Both sources describe the page's product, never a quickview's.
        if (inQuickview(container)) {
            return 0;
        }

        const selectors = [
            typeof prestashop !== 'undefined' &&
                prestashop.selectors &&
                prestashop.selectors.product &&
                prestashop.selectors.product.details,
            '.js-product-details',
            '#product-details',
        ].filter(Boolean);

        for (const selector of selectors) {
            const details = document.querySelector(selector);

            if (details && details.dataset.product) {
                try {
                    const product = JSON.parse(details.dataset.product);

                    return parseInt(product.id_product_attribute || '0', 10) || 0;
                } catch (error) {
                    break;
                }
            }
        }

        // A quickview emits updatedProduct too, so the event is the fallback.
        return eventCombination;
    };

    /**
     * Put the express product in a cart of its own, on a product page.
     *
     * On the cart page the shopper's cart is already the thing being paid for,
     * so nothing is created.
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
            const productId = parseInt(container.dataset.productId || '0', 10) || 0;

            container.moneiCartPromise = request('addToCart', {
                productId,
                productAttributeId: selectedCombination(container),
                groups: selectedGroups(container),
                quantity: selectedQuantity(container),
                // A previous cart for this product is deleted server side, so
                // changing quantity does not leave one abandoned cart per change.
                replacesCartId:
                    parseInt(container.dataset.expressCartId || '0', 10) ||
                    lastCartByProduct[productId] ||
                    0,
            }).then((cart) => {
                container.dataset.expressCartId = String(cart.expressCartId || '');
                lastCartByProduct[productId] = parseInt(cart.expressCartId || '0', 10) || 0;

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
        let cart;

        try {
            cart = await prepareCart(container);
        } catch (error) {
            // Out of stock, below the minimum quantity. At page load the shopper
            // did nothing yet, so there is simply no button; after they changed the
            // product, the change is what failed and they are told.
            // ⚠️ Hidden, not reportLoaded(): that removes the slots, and a later
            // combination that is in stock would have nothing to remount into.
            if (shopperChangedProduct) {
                showError(container, error.message);
            } else {
                container.hidden = true;
            }

            return;
        }

        // ⚠️ A wallet component throws "need amount and currency" when the amount
        // is 0 — an empty cart, or a cart summary hook that rendered before the
        // cart was populated. Skip the button rather than let the SDK throw; the
        // container collapses through reportLoaded's no-support path.
        if (!cart || !cart.amount) {
            reportLoaded(container, slot, false);

            return;
        }

        container.dataset.amount = String(cart.amount);

        const common = {
            accountId: moneiExpress.accountId,
            amount: cart.amount,
            currency: cart.currency,
            onLoad: (isSupported) => reportLoaded(container, slot, isSupported),
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

    /**
     * Record whether a slot's component actually rendered a button.
     *
     * ⚠️ The "Express checkout" label is hidden until the first button reports
     * itself supported, and the whole block collapses once every slot has
     * reported and none is. Without this a browser with no usable wallet — most
     * desktop browsers, for Apple Pay and Google Pay — showed the heading over
     * an empty gap, which reads as the checkout being broken.
     *
     * @param {HTMLElement} container   Express container
     * @param {HTMLElement} slot        Slot the component mounted into
     * @param {boolean}     isSupported What the SDK reported
     */
    const reportLoaded = (container, slot, isSupported) => {
        if (isSupported) {
            container.hidden = false;
            container.querySelectorAll('[data-monei-express-label]').forEach((label) => {
                label.hidden = false;
            });

            return;
        }

        slot.remove();

        if (!container.querySelector('[data-monei-express-method]')) {
            container.hidden = true;
        }
    };

    /**
     * Run a callback once an element is laid out and near the viewport.
     *
     * ⚠️ PrestaShop renders every checkout step in the DOM and hides all but the
     * current one. Mounting a component into a hidden step makes its iframe
     * measure itself at zero height, and it stays that way when the step is
     * shown — a PayPal button that exists in the DOM and is invisible. The
     * product page never hit this because the container is on screen at load.
     *
     * @param {HTMLElement} element  Element to wait for
     * @param {() => void}  callback Run once, when the element is visible
     */
    const whenVisible = (element, callback) => {
        const laidOut = () => element.offsetParent !== null;

        if (laidOut() || typeof IntersectionObserver === 'undefined') {
            callback();

            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    observer.disconnect();
                    callback();
                }
            },
            // Mount as the step comes within reach rather than exactly at the
            // edge, so the shopper does not watch the button appear.
            { rootMargin: '600px 0px' }
        );

        observer.observe(element);
    };

    const init = () => {
        // ⚠️ Checked here, never at load, and retried once the page has loaded:
        // monei.js is a remote script and can still be arriving.
        if (typeof moneiExpress === 'undefined' || typeof monei === 'undefined') {
            if (document.readyState !== 'complete') {
                window.addEventListener('load', init, { once: true });
            }

            return;
        }

        document.querySelectorAll('[data-monei-express]').forEach((container) => {
            if (container.dataset.moneiMounted === '1') {
                return;
            }

            container.dataset.moneiMounted = '1';

            whenVisible(container, () => {
                container.querySelectorAll('[data-monei-express-method]').forEach((slot) => {
                    const method = slot.dataset.moneiExpressMethod;

                    mount(container, slot, method).catch(() => {
                        // Errors are shown only for something the shopper did. A
                        // button that cannot mount is dropped, and the block
                        // collapses when none is left, so there is no empty gap.
                        reportLoaded(container, slot, false);
                    });
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
    const remountProductButtons = (event) => {
        shopperChangedProduct = true;

        if (event && 'id_product_attribute' in event) {
            eventCombination = parseInt(event.id_product_attribute || '0', 10) || 0;
        }

        document
            .querySelectorAll('[data-monei-express][data-location="product"]')
            .forEach((container) => {
                delete container.moneiCartPromise;
                container.dataset.moneiMounted = '';
                // Back to the page-load state: a hidden container is never laid
                // out, so whenVisible() would never mount it again.
                container.hidden = !container.querySelector('[data-monei-express-method]');

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
