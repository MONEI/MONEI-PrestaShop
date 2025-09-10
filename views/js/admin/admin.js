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
                // Use MutationObserver to detect when refund form appears within orderProductsPanel
                const observer = new MutationObserver((mutations) => {
                    // Only look for refund elements within the orderProductsPanel
                    const $orderPanel = $('#orderProductsPanel, .order-products-panel, [id*="orderProducts"]');
                    if ($orderPanel.length === 0) return;
                    
                    // Check if the refund form table appears within the products panel
                    const hasRefundTable = $orderPanel.find('table').filter(function() {
                        return $(this).find('input[id^="cancel_product_amount_"]').length > 0;
                    }).length > 0;
                    
                    // Also check for the refund checkboxes within the panel
                    const hasRefundCheckboxes = $orderPanel.find('input[name="cancel_product[credit_slip]"]').length > 0;
                    
                    if ((hasRefundTable || hasRefundCheckboxes) && $('#monei_credit_slip_reason').length === 0) {
                        this.injectRefundReasonField();
                    }
                });

                // Observe the specific order panel container if it exists, otherwise observe body
                const targetElement = document.getElementById('orderProductsPanel') || 
                                    document.querySelector('.order-products-panel') || 
                                    document.body;
                                    
                observer.observe(targetElement, {
                    childList: true,
                    subtree: true
                });

                // Also handle button clicks as backup - use class or other attributes
                $(document).on('click', 'button[data-action="partial-refund"], button.js-partial-refund-btn, button.partial-refund-btn', () => {
                    // Simple delay to ensure DOM is ready
                    setTimeout(() => {
                        if ($('#monei_credit_slip_reason').length === 0) {
                            this.injectRefundReasonField();
                        }
                    }, 100);
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
                
                // Find the orderProductsPanel first
                const $orderPanel = $('#orderProductsPanel, .order-products-panel, [id*="orderProducts"]').first();
                
                if ($orderPanel.length > 0) {
                    // Find the specific refund table within the panel - the one containing refund amount inputs
                    const $refundTable = $orderPanel.find('table').filter(function() {
                        return $(this).find('input[id^="cancel_product_amount_"]').length > 0;
                    }).first();
                    
                    if ($refundTable.length > 0) {
                        // Insert directly after the table within the panel
                        $refundTable.after(selectHtml);
                    }
                } else {
                    // Fallback: if no panel found, use the original approach
                    const $refundTable = $('table').filter(function() {
                        return $(this).find('input[id^="cancel_product_amount_"]').length > 0;
                    }).first();
                    
                    if ($refundTable.length > 0) {
                        $refundTable.after(selectHtml);
                    }
                }

                // Add data to form submission
                this.interceptFormSubmission();
            },

            buildRefundReasonSelect: function() {
                let optionsHtml = '';
                this.refundReasons.forEach(reason => {
                    const selected = reason.value === 'requested_by_customer' ? 'selected' : '';
                    optionsHtml += `<option value="${reason.value}" ${selected}>${reason.label}</option>`;
                });

                // Use the same layout structure as other fields: row mb-3 > col-md-12 > col-md-12 > info-block
                return `
                    <div class="row mb-3" id="monei_refund_reason_container">
                        <div class="col-md-12">
                            <div class="col-md-12">
                                <div class="info-block">
                                    <div class="d-flex align-items-center">
                                        <label for="monei_credit_slip_reason" class="mb-0 mr-2">
                                            <strong>MONEI refund reason</strong>
                                        </label>
                                        <select id="monei_credit_slip_reason" name="monei_refund_reason" class="form-control" style="width: auto;">
                                            ${optionsHtml}
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            },

            interceptFormSubmission: function() {
                // Intercept refund button click for PrestaShop 8 AJAX submission
                // Use attribute selectors instead of text content
                $(document).off('click.monei').on('click.monei', 'button[type="submit"][name*="cancel"], button[type="submit"][name*="refund"], button.btn-partial-refund, button.btn-standard-refund', function(e) {
                    const refundReason = $('#monei_credit_slip_reason').val() || 'requested_by_customer';
                    
                    // Add to form data if form exists
                    const $form = $('form[name="cancel_product"]');
                    if ($form.length > 0) {
                        if ($form.find('input[name="monei_refund_reason"]').length === 0) {
                            $form.append(`<input type="hidden" name="monei_refund_reason" value="${refundReason}" />`);
                        } else {
                            $form.find('input[name="monei_refund_reason"]').val(refundReason);
                        }
                    }
                });
                
                // Also handle traditional form submission for older PrestaShop versions
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