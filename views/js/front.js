document.addEventListener('DOMContentLoaded', () => {
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
    }
});