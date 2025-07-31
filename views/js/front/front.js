document.addEventListener('DOMContentLoaded', () => {
    // Check if there's a MONEI error to display
    if (typeof moneiCheckoutError !== 'undefined' && moneiCheckoutError) {
        // Show SweetAlert with the error message
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                title: typeof moneiErrorTitle !== 'undefined' ? moneiErrorTitle : 'Payment Error',
                text: moneiCheckoutError,
                icon: 'error',
                confirmButtonText: typeof moneiMsgRetry !== 'undefined' ? moneiMsgRetry : 'OK'
            });
        }
    }
    
    // support module: onepagecheckoutps - v5 - PresTeamShop
    if (typeof OPC !== typeof undefined) {
        prestashop.on('opc-payment-getPaymentList-complete', (params) => {
            if (typeof initMoneiCard !== typeof undefined) {
                initMoneiCard();
            }
            if (typeof initMoneiBizum !== typeof undefined) {
                initMoneiBizum();
            }
            if (typeof initMoneiGooglePay !== typeof undefined) {
                initMoneiGooglePay();
            }
            if (typeof initMoneiApplePay !== typeof undefined) {
                initMoneiApplePay();
            }
            if (typeof initMoneiPayPal !== typeof undefined) {
                initMoneiPayPal();
            }
        });
    // support module: onepagecheckoutps - v4 - PresTeamShop
    } else if (typeof AppOPC !== typeof undefined) {
        $(document).on('opc-load-review:completed', () => {
            if (typeof initMoneiCard !== typeof undefined) {
                initMoneiCard();
            }
            if (typeof initMoneiBizum !== typeof undefined) {
                initMoneiBizum();
            }
            if (typeof initMoneiGooglePay !== typeof undefined) {
                initMoneiGooglePay();
            }
            if (typeof initMoneiApplePay !== typeof undefined) {
                initMoneiApplePay();
            }
            if (typeof initMoneiPayPal !== typeof undefined) {
                initMoneiPayPal();
            }
        });
    } else {
        if (typeof initMoneiCard !== typeof undefined) {
            initMoneiCard();
        }
        if (typeof initMoneiBizum !== typeof undefined) {
            initMoneiBizum();
        }
        if (typeof initMoneiGooglePay !== typeof undefined) {
            initMoneiGooglePay();
        }
        if (typeof initMoneiApplePay !== typeof undefined) {
            initMoneiApplePay();
        }
        if (typeof initMoneiPayPal !== typeof undefined) {
            initMoneiPayPal();
        }
    }
});