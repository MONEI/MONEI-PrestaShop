(function ($) {
    'use strict';

    // Configuración de constantes
    const CONFIG = {
        selectors: {
            productionMode: 'input[name="MONEI_PRODUCTION_MODE"]',
            accountId: '#MONEI_ACCOUNT_ID',
            apiKey: '#MONEI_API_KEY',
            testAccountId: '#MONEI_TEST_ACCOUNT_ID',
            testApiKey: '#MONEI_TEST_API_KEY',
            refundAmount: '#monei_refund_amount',
            refundReason: '#monei_refund_reason',
            refundButton: '#moneiBtnRefund',
            alertBox: '#moneiAlert',
            jsonLog: '#json_log'
        }
    };

    // Funciones de utilidad
    const utils = {
        parseAmount: (value) => parseFloat(value) || 0,
        formatAmount: (cents) => cents / 100,
        getFormGroup: (selector) => $(selector).parents('.form-group')
    };

    // Manejador de modo de producción
    const productionModeHandler = {
        init: function() {
            $(CONFIG.selectors.productionMode).on('change', this.toggleFields);
            $(CONFIG.selectors.productionMode).trigger('change');
        },

        toggleFields: function() {
            const isProduction = $(this).val() === '1';

            utils.getFormGroup(CONFIG.selectors.accountId).toggle(isProduction);
            utils.getFormGroup(CONFIG.selectors.apiKey).toggle(isProduction);
            utils.getFormGroup(CONFIG.selectors.testAccountId).toggle(!isProduction);
            utils.getFormGroup(CONFIG.selectors.testApiKey).toggle(!isProduction);
        }
    };

    // Manejador de reembolsos
    const refundHandler = {
        init: function() {
            this.bindEvents();
        },

        bindEvents: function() {
            $(document).on('change', CONFIG.selectors.refundAmount, this.handleAmountChange.bind(this));
            $(document).on('change', CONFIG.selectors.refundReason, this.updateButtonState.bind(this));
            $(document).on('click', CONFIG.selectors.refundButton, this.handleRefundClick.bind(this));
        },

        getRefundValues: function() {
            const maxRefund = utils.formatAmount($(CONFIG.selectors.refundAmount).data('maxrefund'));
            const refundAmount = utils.parseAmount($(CONFIG.selectors.refundAmount).val());
            const hasValidReason = $(CONFIG.selectors.refundReason + " option:selected").index() !== 0;

            return {
                maxRefund,
                refundAmount,
                hasValidReason,
                isValidAmount: refundAmount > 0 && refundAmount <= maxRefund
            };
        },

        updateButtonState: function() {
            const { hasValidReason, isValidAmount } = this.getRefundValues();
            const isDisabled = !hasValidReason || !isValidAmount;

            $(CONFIG.selectors.refundButton)
                .attr('disabled', isDisabled)
                .toggleClass('disabled', isDisabled);
        },

        handleAmountChange: function() {
            const { isValidAmount } = this.getRefundValues();
            $(CONFIG.selectors.alertBox).toggleClass('collapse', isValidAmount);
            this.updateButtonState();
        },

        handleRefundClick: function() {
            Swal.fire({
                title: MoneiVars.titleRefund,
                text: MoneiVars.textRefund,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: MoneiVars.confirmRefund,
                cancelButtonText: MoneiVars.cancelRefund,
            }).then((result) => {
                if (result.isConfirmed) {
                    this.processRefund();
                }
            });
        },

        processRefund: function() {
            const $button = $(CONFIG.selectors.refundButton);
            const $body = $('body');

            $button.attr('disabled', 'disabled').addClass('disabled');
            $body.css('opacity', '0.5');

            $.ajax({
                type: 'POST',
                url: MoneiVars.adminMoneiControllerUrl,
                data: {
                    controller: 'AdminMonei',
                    action: 'refund',
                    ajax: true,
                    token: MoneiVars.adminMoneiToken,
                    id_order: $('#monei_order_id').val(),
                    amount: $(CONFIG.selectors.refundAmount).val(),
                    reason: $(CONFIG.selectors.refundReason).val(),
                },
                dataType: 'json',
                success: (response) => {
                    Swal.fire(MoneiVars.titleRefund, response.message, 'success')
                        .then(() => location.reload());
                },
                error: (xhr) => {
                    Swal.fire(MoneiVars.titleRefund, xhr.responseJSON.message, 'error');
                },
                complete: () => {
                    $body.css('opacity', '1');
                    $button.attr('disabled', '').removeClass('disabled');
                }
            });
        }
    };

    // Manejador de visualización JSON
    const jsonViewerHandler = {
        init: function() {
            if (typeof MoneiVars === 'undefined') return;

            $('a.fancybox').fancybox({
                helpers: {
                    title: { type: 'inside', position: 'top' }
                },
                width: '90%',
                height: '90%',
                type: 'html',
                overlay: {
                    showEarly: false
                },
                beforeShow: function() {
                    const jsonInfoCoded = atob($(this.element).data("moneijson"));
                    $(CONFIG.selectors.jsonLog).jsonViewer(JSON.parse(jsonInfoCoded), {
                        collapsed: true,
                        rootCollapsable: false
                    });
                    $.fancybox.update();
                },
                afterLoad: function() {
                    this.content = '<pre id="json_log" class="json-document"></pre>';
                }
            });
        }
    };

    // Inicialización
    $(document).ready(function() {
        productionModeHandler.init();
        refundHandler.init();
        jsonViewerHandler.init();
    });

})(jQuery);