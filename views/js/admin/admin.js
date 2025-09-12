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
                            collapsed: false,
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
            // Get refund reasons from PHP (MONEI SDK), fallback to defaults if not available
            refundReasons: (typeof MoneiVars !== 'undefined' && MoneiVars.refundReasons && MoneiVars.refundReasons.length > 0) 
                ? MoneiVars.refundReasons 
                : [
                    { value: 'requested_by_customer', label: 'Requested By Customer' },
                    { value: 'duplicated', label: 'Duplicated' },
                    { value: 'fraudulent', label: 'Fraudulent' }
                ],

            init: function() {
                // Watch for partial refund button clicks
                this.watchForPartialRefundButton();
            },

            watchForPartialRefundButton: function() {
                const self = this;
                
                // Watch for clicks on the partial refund button
                $(document).on('click', 'button.partial-refund-display', function() {
                    // Wait for the form to be fully rendered
                    requestAnimationFrame(() => {
                        requestAnimationFrame(() => {
                            self.checkAndInjectField();
                        });
                    });
                });
                
                // Watch for cancel button to clean up
                $(document).on('click', '.cancel-product-element button.cancel, .cancel-product-element button[name="cancel"]', function() {
                    $('#monei_refund_reason_container').remove();
                });
                
                // Also use MutationObserver as backup to detect when refund inputs appear
                const observer = new MutationObserver(() => {
                    // Check if refund inputs are visible but our field is not
                    if ($('input[id^="cancel_product_quantity_"]:visible').length > 0 && 
                        $('#monei_credit_slip_reason').length === 0) {
                        self.checkAndInjectField();
                    }
                    
                    // Clean up if refund inputs disappear
                    if ($('input[id^="cancel_product_quantity_"]:visible').length === 0 && 
                        $('#monei_refund_reason_container').length > 0) {
                        $('#monei_refund_reason_container').remove();
                    }
                });
                
                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style', 'class'] // Watch for visibility changes
                });
            },
            
            checkAndInjectField: function() {
                // Only inject if refund mode is truly active (visible refund inputs)
                if ($('input[id^="cancel_product_quantity_"]:visible').length > 0 && 
                    $('#monei_credit_slip_reason').length === 0) {
                    this.injectRefundReasonField();
                }
            },


            injectRefundReasonField: function() {
                // Double-check field doesn't already exist
                if ($('#monei_credit_slip_reason').length > 0) {
                    return;
                }

                // Find the products table
                const $refundTable = $('#orderProductsTable');
                
                if ($refundTable.length > 0) {
                    $refundTable.after(this.buildRefundReasonSelect());
                    this.interceptFormSubmission();
                }
            },

            buildRefundReasonSelect: function() {
                let optionsHtml = '';
                this.refundReasons.forEach(reason => {
                    const selected = reason.value === 'requested_by_customer' ? 'selected' : '';
                    optionsHtml += `<option value="${reason.value}" ${selected}>${reason.label}</option>`;
                });

                // Use simplified layout structure without info-block
                return `
                    <div class="row mb-3" id="monei_refund_reason_container">
                        <div class="col-md-12">
                            <div class="col-md-12">
                                <div class="d-flex align-items-center justify-content-end">
                                    <label for="monei_credit_slip_reason" class="mb-0 mr-2">
                                        <strong>${(typeof MoneiVars !== 'undefined' && MoneiVars.refundReasonLabel) ? MoneiVars.refundReasonLabel : 'MONEI refund reason'}</strong>
                                    </label>
                                    <select id="monei_credit_slip_reason" name="monei_refund_reason" class="form-control" style="width: auto;">
                                        ${optionsHtml}
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            },

            interceptFormSubmission: function() {
                // Simple form submission interception
                $(document).off('submit.monei').on('submit.monei', 'form[name="cancel_product"]', function(e) {
                    const $form = $(this);
                    const refundReason = $('#monei_credit_slip_reason').val() || 'requested_by_customer';
                    
                    // Ensure hidden input exists with current value
                    let $hiddenInput = $form.find('input[name="monei_refund_reason"]');
                    if ($hiddenInput.length === 0) {
                        $hiddenInput = $(`<input type="hidden" name="monei_refund_reason" value="${refundReason}" />`);
                        $form.append($hiddenInput);
                    } else {
                        $hiddenInput.val(refundReason);
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