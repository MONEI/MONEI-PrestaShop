/**
 * MONEI storefront payment components.
 *
 * Extracted from views/templates/hook/displayPaymentByBinaries.tpl, which carried
 * this code inline across six <script> blocks. The template now renders markup
 * only, and the values it interpolated arrive through Media::addJsDef, alongside
 * the styles and translated strings the module already passed that way.
 *
 * ⚠️ Nothing here may read those values at load time. The classic theme prints the
 * addJsDef block *after* the external scripts (_partials/javascript.tpl), so they
 * do not exist yet when this file runs. Every read sits inside an init function,
 * and those are called from views/js/front/front.js on DOMContentLoaded.
 *
 * ⚠️ The five initMonei* functions must stay global. front.js calls them by name to
 * re-initialise MONEI after the onepagecheckoutps module rebuilds the payment list.
 *
 * Each init returns early when its own container is absent, which is what makes it
 * safe to define all five unconditionally: the template renders containers only for
 * the payment methods a merchant enabled.
 */

/* ------------------------------------------------------------------ shared */

// Debug logging helper - only logs in development/test mode
var moneiLog = function (level, component, message, data) {
    // Only log if not in production (check for debug mode, test environment, etc.)
    if (
        window.location.hostname === 'localhost' ||
        window.location.hostname.includes('test') ||
        window.location.hostname.includes('dev') ||
        window.location.search.includes('debug=1')
    ) {
        const timestamp = new Date().toISOString();
        const logMessage = `[MONEI ${timestamp}] [${component}] ${message}`;

        if (level === 'error') {
            console.error(logMessage, data || '');
        } else {
            console.log(logMessage, data || '');
        }
    }
};

// Reusable AJAX request handler with error handling
var moneiAjaxRequest = async function (url, options = {}) {
    const defaultOptions = {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
        credentials: 'same-origin', // Include cookies for same-origin requests
        ...options,
    };

    try {
        moneiLog('info', 'Ajax', `Making request to ${url}`, defaultOptions);
        const response = await fetch(url, defaultOptions);

        // Handle empty responses (204, etc.)
        if (response.status === 204 || response.headers.get('content-length') === '0') {
            moneiLog('info', 'Ajax', 'Request successful (empty response)');
            return null;
        }

        // Parse response based on Content-Type
        const contentType = response.headers.get('content-type') || '';
        let data;

        if (contentType.includes('application/json')) {
            try {
                data = await response.json();
            } catch (jsonError) {
                // JSON parsing failed
                moneiLog('error', 'Ajax', 'Invalid JSON response', jsonError);
                data = { error: 'Invalid server response format' };
            }
        } else if (contentType.includes('text/')) {
            // Handle text responses (HTML error pages, plain text, etc.)
            const text = await response.text();
            data = {
                error: 'Server returned non-JSON response',
                message: text.substring(0, 200), // Limit text length for display
                contentType: contentType,
            };
        } else {
            // Handle other content types
            data = {
                error: 'Unexpected response type',
                contentType: contentType,
            };
        }

        if (!response.ok) {
            // Extract error message from response
            const errorMessage =
                data.message || data.error || `HTTP ${response.status}: ${response.statusText}`;
            moneiLog('error', 'Ajax', `Request failed: ${errorMessage}`, {
                status: response.status,
                data,
            });

            // Display error to user
            showMoneiError(errorMessage);

            // Throw error for caller to handle if needed
            const error = new Error(errorMessage);
            error.response = response;
            error.data = data;
            error.status = response.status;
            throw error;
        }

        moneiLog('info', 'Ajax', 'Request successful', data);
        return data;
    } catch (error) {
        // If error already has a response, it was handled above
        if (error.response) {
            throw error;
        }

        // This is a true network error (connection failed, CORS, etc.)
        const errorMessage =
            error.message || 'Request failed. Please check your connection and try again.';
        moneiLog('error', 'Ajax', `Network/Request error: ${errorMessage}`, error);
        showMoneiError(errorMessage);

        // Preserve original error
        throw error;
    }
};

// Show loading overlay
var showMoneiLoading = function () {
    // Create loading overlay
    const overlay = document.createElement('div');
    overlay.id = 'monei-loading-overlay';
    overlay.style.cssText =
        'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
    overlay.innerHTML =
        '<div style="background: white; padding: 30px; border-radius: 8px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">' +
        '<div style="margin-bottom: 15px;">' +
        '<div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite;"></div>' +
        '</div>' +
        '<div style="font-size: 16px; color: #333;">' +
        (typeof moneiProcessingPayment !== 'undefined'
            ? moneiProcessingPayment
            : 'Processing payment...') +
        '</div></div>';

    // Add CSS animation
    const style = document.createElement('style');
    style.textContent =
        '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
    document.head.appendChild(style);

    document.body.appendChild(overlay);

    // Also disable payment confirmation button
    const confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (confirmButton) {
        confirmButton.disabled = true;
        confirmButton.classList.add('disabled');
    }
};

// Hide loading state
var hideMoneiLoading = function () {
    // Remove loading overlay
    const overlay = document.getElementById('monei-loading-overlay');
    if (overlay) {
        overlay.remove();
    }

    // Re-enable payment confirmation button
    const confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
    if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.classList.remove('disabled');
    }
};

// Show error using PrestaShop's native notification structure
var showMoneiError = function (message) {
    hideMoneiLoading();

    // Find the existing notifications container
    const notificationContainer = document.querySelector('#notifications');

    if (notificationContainer) {
        // Check if notifications container already has a container class div
        let containerDiv = notificationContainer.querySelector(
            '.container, .notifications-container'
        );

        if (!containerDiv) {
            // Add container div to match page width
            containerDiv = document.createElement('div');
            containerDiv.className = 'container';
            notificationContainer.appendChild(containerDiv);
        }

        // Remove only previous MONEI alerts, keep other notices intact
        containerDiv.querySelectorAll('.monei-payment-alert').forEach((el) => el.remove());

        // Create the alert structure
        const alert = document.createElement('article');
        alert.className = 'alert alert-danger monei-payment-alert';
        alert.setAttribute('role', 'alert');
        alert.setAttribute('data-alert', 'danger');

        const list = document.createElement('ul');
        const listItem = document.createElement('li');
        listItem.textContent = message;

        list.appendChild(listItem);
        alert.appendChild(list);

        // Add alert to container
        containerDiv.appendChild(alert);

        // Scroll to the notification
        notificationContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });

        // Auto-dismiss after 10 seconds with fade effect
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 10000);
    } else {
        // If no notifications container exists, insert alert in the payment section
        const paymentSection = document.querySelector(
            '#checkout-payment-step, .checkout-step.-current, .payment-options'
        );

        if (paymentSection) {
            // Remove any existing MONEI alerts
            paymentSection.querySelectorAll('.monei-payment-alert').forEach((el) => el.remove());

            // Create alert
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger monei-payment-alert';
            alert.setAttribute('role', 'alert');

            const list = document.createElement('ul');
            const listItem = document.createElement('li');
            listItem.textContent = message;

            list.appendChild(listItem);
            alert.appendChild(list);

            // Insert at the top of payment section
            paymentSection.insertBefore(alert, paymentSection.firstChild);

            // Scroll to the alert
            alert.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Auto-dismiss after 10 seconds with fade effect
            setTimeout(() => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            }, 10000);
        } else {
            // Last resort: use JavaScript alert
            alert(message);
        }
    }
};

var moneiTokenHandler = async (parameters = {}) => {
    const { paymentToken, cardholderName = null, moneiConfirmationButton = null } = parameters;

    const createMoneiPayment = async () => {
        // Failures propagate untouched: moneiAjaxRequest has already shown the
        // error to the shopper, and the caller decides what to do next.
        const data = await moneiAjaxRequest(moneiCreatePaymentUrlController, {
            body: JSON.stringify({ token: moneiToken }),
        });

        // Check if we got a valid response
        if (!data || !data.moneiPaymentId) {
            throw new Error('Invalid payment response from server');
        }

        return data.moneiPaymentId;
    };

    const params = { paymentToken };
    if (cardholderName) {
        params.paymentMethod = { card: { cardholderName } };
    }

    const saveCard = document.getElementById('monei-tokenize-card');
    if (saveCard?.checked) params.generatePaymentToken = true;

    showMoneiLoading();

    try {
        params.paymentId = await createMoneiPayment();
    } catch (error) {
        if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
        return;
    }

    try {
        const result = await monei.confirmPayment(params);
        handleMoneiTokenResult(result, moneiConfirmationButton);
    } catch (error) {
        handleMoneiTokenError(error, params, moneiConfirmationButton);
    }
};

var handleMoneiTokenResult = (result, moneiConfirmationButton) => {
    if (result.nextAction?.mustRedirect) {
        location.assign(result.nextAction.redirectUrl);
    } else if (result.nextAction?.redirectUrl) {
        // Always redirect to complete URL for unified flow - let confirmation controller handle success/failure
        location.assign(result.nextAction.redirectUrl);
    } else {
        // Fallback for cases without redirectUrl (shouldn't happen with single complete URL approach)
        hideMoneiLoading();
        showMoneiError(
            result.statusMessage ||
                (typeof moneiPaymentProcessed !== 'undefined'
                    ? moneiPaymentProcessed
                    : 'Payment processed')
        );
        if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
    }
};

var handleMoneiTokenError = (error, params, moneiConfirmationButton) => {
    // Check if error response has redirectUrl for unified flow
    if (error.nextAction?.redirectUrl) {
        location.assign(error.nextAction.redirectUrl);
    } else {
        // Fallback to showing error
        hideMoneiLoading();
        showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
        if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
    }
    moneiLog('error', 'TokenHandler', 'Payment error occurred', { params, error });
};

var moneiValidConditions = () => {
    const conditionsToApprove = document.getElementById('conditions-to-approve');
    if (conditionsToApprove) {
        const requiredCheckboxes = conditionsToApprove.querySelectorAll(
            'input[type="checkbox"][required]'
        );
        return Array.from(requiredCheckboxes).every((checkbox) => checkbox.checked);
    }
    return true;
};

var moneiAddChangeEventToCheckboxes = (moneiButton) => {
    const conditionsToApprove = document.getElementById('conditions-to-approve');
    if (conditionsToApprove) {
        const requiredCheckboxes = conditionsToApprove.querySelectorAll(
            'input[type="checkbox"][required]'
        );
        requiredCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                moneiValidConditions()
                    ? moneiEnableButton(moneiButton)
                    : moneiDisableButton(moneiButton);
            });
        });
    }
};

var moneiEnableButton = (moneiButton) => {
    if (moneiButton) {
        setTimeout(() => {
            moneiButton.classList.remove('disabled');
            moneiButton.disabled = false;
        }, 0);
    }
};

var moneiDisableButton = (moneiButton) => {
    if (moneiButton) {
        moneiButton.classList.add('disabled');
        moneiButton.disabled = true;
    }
};

/* ------------------------------------------------------------------- bizum */

var processingMoneiBizumPayment = false;

function initMoneiBizum() {
    const moneiBizumButtonsContainer = document.getElementById('monei-bizum-buttons-container');
    if (!moneiBizumButtonsContainer) return;

    const moneiBizumRenderContainer =
        moneiBizumButtonsContainer.querySelector('.monei-bizum_render');
    if (!moneiBizumRenderContainer) return;

    monei
        .Bizum({
            accountId: moneiAccountId,
            // Required from monei.js v3 onward, as for CardInput.
            amount: moneiCurrentAmount(),
            currency: moneiCurrency,
            style: moneiBizumStyle || {},
            onBeforeOpen() {
                if (!moneiValidConditions() || processingMoneiBizumPayment) {
                    return false;
                }
                processingMoneiBizumPayment = true;
                return true;
            },
            onLoad() {
                processingMoneiBizumPayment = false;
            },
            onSubmit({ token }) {
                if (token) {
                    moneiTokenHandler({ paymentToken: token });
                }
            },
            onError({ status, statusCode, message }) {
                showMoneiError(`${status} (${statusCode}): ${message}`);
                moneiLog('error', 'Bizum', `Payment error: ${status} (${statusCode})`, {
                    status,
                    statusCode,
                    message,
                });
            },
        })
        .render(moneiBizumRenderContainer);
}

/**
 * The amount to initialise a component with, in minor units.
 *
 * ⚠️ Read from the rendered payment section when it carries one, and only then
 * from the page-load global. onepagecheckoutps rebuilds the payment list over
 * AJAX after a carrier, coupon or quantity change and calls the init functions
 * again — the global still holds the total from page load, while the server
 * creates the payment from the current cart. A component initialised from the
 * stale global shows one amount and confirms another.
 */
const moneiCurrentAmount = () => {
    const section = document.querySelector('.js-payment-monei[data-monei-amount]');
    const fromMarkup = section ? parseInt(section.dataset.moneiAmount, 10) : NaN;

    return Number.isFinite(fromMarkup) && fromMarkup > 0 ? fromMarkup : moneiAmount;
};

/* -------------------------------------------------------------------- card */

// A woff2 subset renders the card only if its unicode-range covers the glyphs
// the field shows: digits and basic latin. The range is CSS like "U+0-FF, U+131".
function moneiUnicodeRangeCovers(range, codePoint) {
    if (!range) return true; // no range declared means the face covers everything
    for (let token of range.split(',')) {
        token = token.trim().replace(/^U\+/i, '');
        if (token.includes('?')) {
            const low = parseInt(token.replace(/\?/g, '0'), 16);
            const high = parseInt(token.replace(/\?/g, 'F'), 16);
            if (codePoint >= low && codePoint <= high) return true;
        } else if (token.includes('-')) {
            const [low, high] = token.split('-');
            if (codePoint >= parseInt(low, 16) && codePoint <= parseInt(high, 16)) return true;
        } else if (codePoint === parseInt(token, 16)) {
            return true;
        }
    }
    return false;
}

// The card runs in a cross-origin iframe, so it cannot use a webfont the theme
// loaded on the parent page. Where the store font is a same-origin webfont
// (hummingbird ships Inter this way), resolve its woff2, inline it, and hand the
// face to the SDK so the field renders the real glyphs, not a fallback sans.
async function moneiResolveStoreFontFace(family, weight) {
    const target = parseInt(weight, 10) || 400;
    const wanted = family.replace(/["']/g, '').trim().toLowerCase();
    const candidates = [];

    for (const sheet of document.styleSheets) {
        let rules;
        try {
            rules = sheet.cssRules;
        } catch (e) {
            continue; // cross-origin stylesheet, unreadable
        }
        if (!rules) continue;

        for (const rule of rules) {
            if (!(rule instanceof CSSFontFaceRule)) continue;
            if (
                (rule.style.fontFamily || '').replace(/["']/g, '').trim().toLowerCase() !== wanted
            ) {
                continue;
            }

            const woff2 = /url\(\s*['"]?([^'")]+\.woff2[^'")]*)['"]?\s*\)/i.exec(
                rule.style.src || ''
            );
            if (!woff2) continue;

            let abs;
            try {
                abs = new URL(woff2[1], sheet.href || document.baseURI).href;
            } catch (e) {
                continue;
            }
            if (new URL(abs).origin !== window.location.origin) continue; // fetch would be a CORS miss

            const range = rule.style.unicodeRange || '';
            candidates.push({
                abs,
                w: parseInt(rule.style.fontWeight, 10) || 400,
                coversLatin:
                    moneiUnicodeRangeCovers(range, 0x30) && moneiUnicodeRangeCovers(range, 0x41),
            });
        }
    }

    if (!candidates.length) return null;

    const latin = candidates.filter((c) => c.coversLatin);
    const pool = latin.length ? latin : candidates;
    pool.sort((a, b) => Math.abs(a.w - target) - Math.abs(b.w - target));

    return pool[0].abs;
}

async function moneiApplyStoreWebfont(component, refStyle) {
    try {
        const family = (refStyle.fontFamily || '').split(',')[0].replace(/["']/g, '').trim();
        if (!family || typeof component.updateProps !== 'function') return;

        const url = await moneiResolveStoreFontFace(family, refStyle.fontWeight);
        if (!url) return; // system font, or a cross-origin webfont we cannot fetch

        const response = await fetch(url);
        if (!response.ok) return;

        const blob = await response.blob();
        const dataUrl = await new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = reject;
            reader.readAsDataURL(blob);
        });

        // A bare data: URL. The v3 loader validates src with new URL(), which
        // rejects a url(...) wrapper.
        await component.updateProps({
            fonts: [{ family, src: dataUrl, weight: parseInt(refStyle.fontWeight, 10) || 400 }],
        });
    } catch (e) {
        // Best effort: the field already renders with the family-name fallback.
    }
}

function initMoneiCard() {
    const sectionMoneiCard = document.querySelector('.js-payment-monei-card');
    if (!sectionMoneiCard) return;

    const moneiCardButtonsContainer = document.getElementById('monei-card-buttons-container');
    if (!moneiCardButtonsContainer) return;

    const moneiPaymentForm = moneiCardButtonsContainer.querySelector('form');
    if (!moneiPaymentForm) return;

    const moneiConfirmationButton = moneiPaymentForm.querySelector('button[type="submit"]');
    if (!moneiConfirmationButton) return;

    // Single is the default; 'split' opts in to three separate fields.
    const moneiCardLayoutInUse =
        typeof moneiCardLayout !== 'undefined' && moneiCardLayout === 'split' ? 'split' : 'single';

    // The container the template rendered decides what can mount, and the layout
    // follows it: a cached template from before split fields existed renders only
    // the single container, and honouring the setting over the markup would show
    // no card field and no error. Whichever container exists wins.
    const moneiCardSplitContainer = document.getElementById('monei-card-number');
    const moneiCardSingleContainer = document.getElementById('monei-card_container');
    const moneiCardRenderContainer =
        moneiCardLayoutInUse === 'split'
            ? moneiCardSplitContainer || moneiCardSingleContainer
            : moneiCardSingleContainer || moneiCardSplitContainer;
    if (!moneiCardRenderContainer) return;
    const moneiCardLayoutMounted =
        moneiCardRenderContainer === moneiCardSplitContainer ? 'split' : 'single';

    const moneiCardHolderName = document.getElementById('monei-card-holder-name');
    const moneiCardErrors = document.getElementById('monei-card-errors');
    // Both are dereferenced later, after the component has mounted. Missing
    // either would leave a working card field with a Pay button that does
    // nothing, which is worse than no field at all.
    if (!moneiCardHolderName || !moneiCardErrors) {
        moneiLog(
            'error',
            'CardInput',
            'Card template is missing the holder name or errors element'
        );
        return;
    }

    moneiAddChangeEventToCheckboxes(moneiConfirmationButton);
    moneiValidConditions()
        ? moneiEnableButton(moneiConfirmationButton)
        : moneiDisableButton(moneiConfirmationButton);

    const validateMoneiCardHolderName = (name) => {
        const patternCardHolderName = /^[A-Za-zÀ-ú- ]{5,50}$/;
        const isValid = patternCardHolderName.test(name);
        moneiCardErrors.innerHTML = isValid
            ? ''
            : `<div class="alert alert-danger">${moneiCardHolderNameNotValid}</div>`;
        return isValid;
    };

    const moneiCardStyle = moneiCardInputStyle || {};

    // Match the card iframe text to the store's own fields. monei.js cannot read
    // the store font cross-origin, so resolve it here from the sibling holder-name
    // input and pass it through style.base. A merchant-configured value wins.
    moneiCardStyle.base = moneiCardStyle.base || {};
    const moneiRefFieldStyle = window.getComputedStyle(moneiCardHolderName);
    if (!moneiCardStyle.base.fontFamily && moneiRefFieldStyle.fontFamily) {
        moneiCardStyle.base.fontFamily = moneiRefFieldStyle.fontFamily;
    }
    if (!moneiCardStyle.base.fontSize && moneiRefFieldStyle.fontSize) {
        moneiCardStyle.base.fontSize = moneiRefFieldStyle.fontSize;
    }

    // Size the SDK field from the theme's own input rather than a fixed height, so
    // the card field matches whatever theme is installed.
    //
    // ⚠️ Computed metrics, never offsetHeight/clientHeight. This runs while the
    // card form is still display:none — its payment option has not been selected
    // yet — and a layout read returns 0 there, which the SDK takes at face value
    // and mounts a zero-height frame that never appears. Padding and line height
    // resolve even on a hidden element.
    //
    // Content + padding is what the container contributes once its own vertical
    // padding is dropped (see checkout_page.css), so the card field and the
    // card-holder input come out the same height.
    // ⚠️ The height goes on our own container, not into the SDK's style. The SDK
    // sizes its frame from a layout read of the mount point, and that read happens
    // while the card form is still display:none — it resolves to 0 and the frame
    // collapses to nothing. Our container carries the height instead and centres
    // whatever the SDK renders inside it.
    const moneiPx = (value) => parseFloat(value) || 0;
    const moneiRefLineHeight =
        moneiPx(moneiRefFieldStyle.lineHeight) || moneiPx(moneiRefFieldStyle.fontSize) * 1.5;
    const moneiRefHeight =
        moneiRefLineHeight +
        moneiPx(moneiRefFieldStyle.paddingTop) +
        moneiPx(moneiRefFieldStyle.paddingBottom) +
        moneiPx(moneiRefFieldStyle.borderTopWidth) +
        moneiPx(moneiRefFieldStyle.borderBottomWidth);

    const moneiCardForm = document.getElementById('payment-form-monei');
    if (moneiCardForm && moneiRefHeight) {
        moneiCardForm.style.setProperty('--monei-field-height', Math.round(moneiRefHeight) + 'px');
    }

    const moneiOnCardChange = (event) => {
        // Handle real-time validation errors
        if (event.isTouched !== false && event.error) {
            moneiCardRenderContainer.classList.add('is-invalid');
            moneiCardErrors.innerHTML = `<div class="alert alert-danger">${event.error}</div>`;
        } else {
            moneiCardRenderContainer.classList.remove('is-invalid');
            moneiCardErrors.innerHTML = '';
        }
    };

    let moneiCardInput;

    if (moneiCardLayoutMounted === 'split') {
        // One CardGroup carries the payment details; the three parts are
        // presentation only.
        //
        // ⚠️ Amount and currency belong on the group. A part throws when given
        // either, and the whole card field then fails to mount.
        const moneiCardGroup = monei.CardGroup({
            accountId: moneiAccountId,
            amount: moneiCurrentAmount(),
            currency: moneiCurrency,
            language: prestashop.language.iso_code,
            style: moneiCardStyle,
            onChange: moneiOnCardChange,
            onEnter: () => {
                moneiConfirmationButton.click();
            },
        });

        [
            [monei.CardNumber, 'monei-card-number'],
            [monei.CardExpiry, 'monei-card-expiry'],
            [monei.CardCvc, 'monei-card-cvc'],
        ].forEach(([part, containerId]) => {
            const container = document.getElementById(containerId);

            if (container) {
                part({ group: moneiCardGroup }).render(container);
            }
        });

        // The group is what a token is created from, exactly like CardInput.
        moneiCardInput = moneiCardGroup;
    } else {
        moneiCardInput = monei.CardInput({
            accountId: moneiAccountId,
            // ⚠️ Required from monei.js v3 onward. v2 accepted an accountId alone;
            // v3 throws "You need to provide paymentId or accountId amount and
            // currency" and renders no iframe, so the checkout shows no card field.
            amount: moneiCurrentAmount(),
            currency: moneiCurrency,
            onFocus: () => {
                moneiCardRenderContainer.classList.add('is-focused');
            },
            onBlur: () => {
                moneiCardRenderContainer.classList.remove('is-focused');
            },
            onChange: moneiOnCardChange,
            onEnter: () => {
                moneiConfirmationButton.click();
            },
            language: prestashop.language.iso_code,
            style: moneiCardStyle,
        });

        moneiCardInput.render(moneiCardRenderContainer);
    }

    moneiApplyStoreWebfont(moneiCardInput, moneiRefFieldStyle);

    moneiCardHolderName.addEventListener('blur', (event) => {
        validateMoneiCardHolderName(event.currentTarget.value);
    });

    moneiPaymentForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        e.stopPropagation();

        moneiDisableButton(moneiConfirmationButton);

        if (
            !moneiPaymentForm.checkValidity() ||
            !validateMoneiCardHolderName(moneiCardHolderName.value)
        ) {
            moneiEnableButton(moneiConfirmationButton);
            return;
        }

        try {
            // ⚠️ `component.submit()`, not `monei.createToken(component)`. A
            // CardGroup rejects createToken with "Index is not registered", and
            // the shopper is left staring at a filled in form that will not pay.
            // submit() is what both layouts support — it is also what the
            // WooCommerce plugin uses for each of them.
            const { token, error } = await moneiCardInput.submit();
            if (!token) {
                moneiCardRenderContainer.classList.add('is-invalid');
                moneiCardErrors.innerHTML = `<div class="alert alert-danger">${error}</div>`;
                moneiEnableButton(moneiConfirmationButton);
                return;
            }

            moneiCardRenderContainer.classList.remove('is-invalid');
            moneiCardErrors.innerHTML = '';
            await moneiTokenHandler({
                paymentToken: token,
                cardholderName: moneiCardHolderName.value,
                moneiConfirmationButton,
            });
        } catch (error) {
            moneiCardRenderContainer.classList.add('is-invalid');
            moneiCardErrors.innerHTML = `<div class="alert alert-danger">${error.message}</div>`;
            moneiEnableButton(moneiConfirmationButton);
            moneiLog('error', 'CardInput', 'Failed to create token', error);
        }
    });
}

/* -------------------------------------------------------------- google pay */

function initMoneiGooglePay() {
    // Safari gets Apple Pay instead. Previously this function was only declared
    // inside that browser check, which is a function declaration in a block; the
    // check now lives here, where it is unambiguous. front.js calls both, and the
    // one that does not apply returns immediately.
    if (typeof window.ApplePaySession !== 'undefined') return;

    const moneiPaymentRequestButtonsContainer = document.getElementById(
        'monei-googlePay-buttons-container'
    );
    if (!moneiPaymentRequestButtonsContainer) return;

    const moneiPaymentRequestRenderContainer =
        moneiPaymentRequestButtonsContainer.querySelector('.monei-googlePay_render');
    if (!moneiPaymentRequestRenderContainer) return;

    monei
        .PaymentRequest({
            accountId: moneiAccountId,
            style: moneiPaymentRequestStyle || {},
            amount: moneiCurrentAmount(),
            currency: moneiCurrency,
            onBeforeOpen: moneiValidConditions,
            onSubmit(result) {
                if (result.token) moneiTokenHandler({ paymentToken: result.token });
            },
            onError(error) {
                showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
                moneiLog(
                    'error',
                    'GooglePay',
                    `Payment error: ${error.status} (${error.statusCode})`,
                    error
                );
            },
        })
        .render(moneiPaymentRequestRenderContainer);
}

/* --------------------------------------------------------------- apple pay */

function initMoneiApplePay() {
    if (!window.ApplePaySession?.canMakePayments()) {
        // Not merely "do nothing": a device that cannot pay with Apple Pay must not
        // be offered it, so the option is removed from the list. This ran at load
        // time while the code was inline; it now runs on init, which front.js calls
        // on DOMContentLoaded.
        const moneiPaymentOption = document.querySelector(
            'input[name="payment-option"][data-module-name="monei-applePay"]'
        );
        if (moneiPaymentOption) {
            const moneiPaymentOptionParent = moneiPaymentOption.closest(
                '.payment-option, .payment__option'
            );
            if (moneiPaymentOptionParent) {
                moneiPaymentOptionParent.style.setProperty('display', 'none', 'important');
            }
        }
        return;
    }

    const moneiPaymentRequestButtonsContainer = document.getElementById(
        'monei-applePay-buttons-container'
    );
    if (!moneiPaymentRequestButtonsContainer) return;

    const moneiPaymentRequestRenderContainer =
        moneiPaymentRequestButtonsContainer.querySelector('.monei-applePay_render');
    if (!moneiPaymentRequestRenderContainer) return;

    monei
        .PaymentRequest({
            accountId: moneiAccountId,
            style: moneiPaymentRequestStyle || {},
            amount: moneiCurrentAmount(),
            currency: moneiCurrency,
            onBeforeOpen: moneiValidConditions,
            onSubmit(result) {
                if (result.token) moneiTokenHandler({ paymentToken: result.token });
            },
            onError(error) {
                showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
                moneiLog(
                    'error',
                    'ApplePay',
                    `Payment error: ${error.status} (${error.statusCode})`,
                    error
                );
            },
        })
        .render(moneiPaymentRequestRenderContainer);
}

/* ------------------------------------------------------------------ paypal */

var processingMoneiPayPalPayment = false;

function initMoneiPayPal() {
    const moneiPayPalButtonsContainer = document.getElementById('monei-paypal-buttons-container');
    if (!moneiPayPalButtonsContainer) return;

    const moneiPayPalRenderContainer =
        moneiPayPalButtonsContainer.querySelector('.monei-paypal_render');
    if (!moneiPayPalRenderContainer) return;

    const paypalConfig = {
        accountId: moneiAccountId,
        language: prestashop.language.iso_code,
        style: moneiPayPalStyle || {},
        amount: moneiCurrentAmount(),
        currency: moneiCurrency,
        transactionType: moneiPaymentAction === 'auth' ? 'AUTH' : 'SALE',
        onLoad() {
            processingMoneiPayPalPayment = false;
        },
        onBeforeOpen() {
            if (!moneiValidConditions() || processingMoneiPayPalPayment) {
                return false;
            }
            processingMoneiPayPalPayment = true;
            return true;
        },
        onSubmit(result) {
            if (result.error) {
                showMoneiError(
                    result.error.message ||
                        (typeof moneiErrorOccurredWithPayPal !== 'undefined'
                            ? moneiErrorOccurredWithPayPal
                            : 'An error occurred with PayPal')
                );
                moneiLog('error', 'PayPal', 'Payment submission error', result.error);
                processingMoneiPayPalPayment = false;
            } else if (result.token) {
                moneiTokenHandler({ paymentToken: result.token });
            }
        },
        onError(error) {
            showMoneiError(
                `${error.status || (typeof moneiErrorOccurred !== 'undefined' ? moneiErrorOccurred : 'Error')} ${error.statusCode ? `(${error.statusCode})` : ''}: ${error.message || (typeof moneiErrorOccurredWithPayPal !== 'undefined' ? moneiErrorOccurredWithPayPal : 'An error occurred with PayPal')}`
            );
            moneiLog(
                'error',
                'PayPal',
                `Payment error: ${error.status || 'Unknown'} ${error.statusCode ? `(${error.statusCode})` : ''}`,
                error
            );
            processingMoneiPayPalPayment = false;
        },
    };

    monei.PayPal(paypalConfig).render(moneiPayPalRenderContainer);
}
