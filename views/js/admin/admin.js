(function () {
    "use strict";

    // Wait for jQuery to be available
    function waitForJQuery(callback) {
        if (typeof window.jQuery !== 'undefined') {
            callback(window.jQuery);
        } else if (typeof window.$ !== 'undefined') {
            callback(window.$);
        } else {
            setTimeout(function() {
                waitForJQuery(callback);
            }, 100);
        }
    }

    // Initialize when jQuery is ready
    waitForJQuery(function($) {
        $(document).ready(function() {
            initializeMoneiAdmin($);
        });
    });

    function initializeMoneiAdmin($) {
        // Configuration constants
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

        // Utility functions
        const utils = {
            parseAmount: (value) => parseFloat(value) || 0,
            formatAmount: (cents) => cents / 100,
            getFormGroup: (selector) => $(selector).parents('.form-group')
        };

        // Simple toggle function
        function toggleFields() {
            var isProduction = $('input[name="MONEI_PRODUCTION_MODE"]:checked').val() === '1';
            
            // Show/hide production fields
            $('.monei-production-field').closest('.form-group').toggle(isProduction);
            
            // Show/hide test fields  
            $('.monei-test-field').closest('.form-group').toggle(!isProduction);
        }

        // Production mode handler (updated for reliability)
        const productionModeHandler = {
            init: function() {
                // Set up radio button change handler
                $('input[name="MONEI_PRODUCTION_MODE"]').change(toggleFields);
                
                // Initial toggle
                toggleFields();
                
                // Re-apply toggle when switching to settings tab
                $(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function(e) {
                    if ($(e.target).attr('href') === '#panel-conf-1') {
                        toggleFields();
                    }
                });
            }
        };

        // JSON viewer handler
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

        // Capture payment handler
        const capturePaymentHandler = {
            init: function() {
                $(document).on('click', '.monei-capture-payment-btn', this.handleCaptureClick);
            },

            handleCaptureClick: function(e) {
                e.preventDefault();
                
                const $btn = $(this);
                const orderId = $btn.data('order-id');
                const amount = $btn.data('amount');
                const amountFormatted = $btn.data('amount-formatted');
                const captureUrl = $btn.data('capture-url');

                if (!orderId || !amount || !captureUrl) {
                    alert('Missing required data for capture operation');
                    return;
                }

                // Confirm the capture action
                if (!confirm(`Are you sure you want to capture this payment?\n\nAmount: ${amountFormatted}\nOrder ID: ${orderId}`)) {
                    return;
                }

                // Disable button and show loading state
                const originalText = $btn.html();
                $btn.prop('disabled', true).html('<i class="icon-spinner icon-spin"></i> Capturing...');

                // Make AJAX request to capture the payment
                // Using promise-based approach for better jQuery 3.x compatibility
                $.ajax({
                    url: captureUrl,
                    type: 'POST',
                    data: {
                        ajax: true,
                        action: 'capturePayment',
                        id_order: orderId,
                        amount: amount
                    }
                }).done(function(response) {
                    try {
                        const result = typeof response === 'string' ? JSON.parse(response) : response;
                        
                        if (result.success) {
                            // Show success message
                            if (typeof showSuccessMessage === 'function') {
                                showSuccessMessage(result.message);
                            } else {
                                alert('Success: ' + result.message);
                            }
                            
                            // Remove the capture button as payment is now captured
                            $btn.closest('.btn-group').fadeOut();
                            
                            // Optionally reload the page to show updated status
                            setTimeout(function() {
                                window.location.reload();
                            }, 1500);
                        } else {
                            // Show error message
                            if (typeof showErrorMessage === 'function') {
                                showErrorMessage(result.message);
                            } else {
                                alert('Error: ' + result.message);
                            }
                            
                            // Restore button
                            $btn.prop('disabled', false).html(originalText);
                        }
                    } catch (e) {
                        alert('Error processing response: ' + e.message);
                        $btn.prop('disabled', false).html(originalText);
                    }
                }).fail(function(xhr, status, error) {
                    alert('AJAX Error: ' + error);
                    $btn.prop('disabled', false).html(originalText);
                });
            }
        };

        // Initialize all handlers
        productionModeHandler.init();
        jsonViewerHandler.init();
        creditSlipHandler.init();
        capturePaymentHandler.init();
    }
})();