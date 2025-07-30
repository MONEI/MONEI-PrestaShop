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

    // Credit slip refund reason handler
    const creditSlipHandler = {
        refundReasons: [
            { value: 'requested_by_customer', label: 'Requested by customer' },
            { value: 'duplicate', label: 'Duplicate' },
            { value: 'fraudulent', label: 'Fraudulent' },
            { value: 'other', label: 'Other' }
        ],

        init: function() {
            // Watch for credit slip form appearance in PrestaShop 8
            this.observeCreditSlipForm();
            
            // Also handle legacy form if present
            this.handleLegacyForm();
        },

        observeCreditSlipForm: function() {
            // Use MutationObserver to detect when credit slip form is added to DOM
            const observer = new MutationObserver((mutations) => {
                mutations.forEach((mutation) => {
                    mutation.addedNodes.forEach((node) => {
                        if (node.nodeType === 1) { // Element node
                            // Check for credit slip form elements
                            const creditSlipForm = $(node).find('#order_credit_slip_form, [name="cancel_product"]').length > 0 ||
                                                 $(node).is('#order_credit_slip_form, [name="cancel_product"]');
                            
                            if (creditSlipForm) {
                                setTimeout(() => this.injectRefundReasonField(), 100);
                            }
                        }
                    });
                });
            });

            // Start observing
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        },

        handleLegacyForm: function() {
            // For immediate injection if form is already present
            if ($('#order_credit_slip_form, [name="cancel_product"]').length > 0) {
                this.injectRefundReasonField();
            }
        },

        injectRefundReasonField: function() {
            // Don't inject if already exists
            if ($('#monei_credit_slip_reason').length > 0) {
                return;
            }

            // Build the select field HTML
            const selectHtml = this.buildRefundReasonSelect();
            
            // Find suitable injection points for PrestaShop 8 order page
            const injectionPoints = [
                '.cancel-product-element:last', // New order page
                '.standard-refund-fields:last', // Standard refund
                '.partial-refund-fields:last', // Partial refund
                '[name="cancel_product_credit_slip"]:last', // Credit slip checkbox
                '.form-group:has([name="cancel_product_credit_slip"])', // Form group containing credit slip
            ];

            let injected = false;
            for (const selector of injectionPoints) {
                const $element = $(selector);
                if ($element.length > 0) {
                    $element.after(selectHtml);
                    injected = true;
                    break;
                }
            }

            // If no suitable injection point found, try to append to form
            if (!injected) {
                const $form = $('#order_credit_slip_form, form[name="cancel_product"]');
                if ($form.length > 0) {
                    $form.append(selectHtml);
                }
            }

            // Add data to form submission
            this.interceptFormSubmission();
        },

        buildRefundReasonSelect: function() {
            let optionsHtml = '<option value="">-- Select refund reason --</option>';
            this.refundReasons.forEach(reason => {
                optionsHtml += `<option value="${reason.value}">${reason.label}</option>`;
            });

            return `
                <div class="form-group" id="monei_refund_reason_group">
                    <label class="control-label">MONEI Refund Reason</label>
                    <select id="monei_credit_slip_reason" name="monei_refund_reason" class="form-control">
                        ${optionsHtml}
                    </select>
                </div>
            `;
        },

        interceptFormSubmission: function() {
            // Intercept form submission to include refund reason
            $(document).off('submit.monei').on('submit.monei', '#order_credit_slip_form, form[name="cancel_product"]', function(e) {
                const $form = $(this);
                const refundReason = $('#monei_credit_slip_reason').val() || 'requested_by_customer';
                
                // Add hidden input with refund reason if not exists
                if ($form.find('input[name="monei_refund_reason"]').length === 0) {
                    $form.append(`<input type="hidden" name="monei_refund_reason" value="${refundReason}" />`);
                } else {
                    $form.find('input[name="monei_refund_reason"]').val(refundReason);
                }
            });
        }
    };

    // Inicialización
    $(document).ready(function() {
        productionModeHandler.init();
        jsonViewerHandler.init();
        creditSlipHandler.init();
    });

})(jQuery);