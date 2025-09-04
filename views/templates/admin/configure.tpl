<div class="container-fluid">
    <!-- Normal UI -->
    <div class="row">
        <div class="col-md-12">
            <div class="tabbable" id="tabs-270581">
                <ul class="nav nav-tabs">
                    <li class="{if !(isset($pbtab))}active{/if}">
                        <a class="nav-link" href="#panel-info" data-toggle="tab"><i class="icon icon-info-circle"></i>
                            {l s='Information' mod='monei'}</a>
                    </li>
                    <li>
                        <a class="nav-link" href="#panel-conf-1" data-toggle="tab"><i class="icon icon-cogs"></i>
                            {l s='Settings' mod='monei'}</a>
                    </li>
                    <li>
                        <a class="nav-link" href="#panel-conf-2" data-toggle="tab"><i class="icon icon-money"></i>
                            {l s='Payment methods' mod='monei'}</a>
                    </li>
                    <li>
                        <a class="nav-link" href="#panel-conf-3" data-toggle="tab"><i
                                class="icon icon-shopping-cart"></i>
                            {l s='Payment Status' mod='monei'}</a>
                    </li>
                    <li>
                        <a class="nav-link" href="#panel-conf-4" data-toggle="tab"><i class="icon icon-paint-brush"></i>
                            {l s='Component Style' mod='monei'}</a>
                    </li>
                </ul>
                <!-- TABS -->
                <div class="tab-content">
                    <!-- INFORMATION AND CONTROLS -->
                    <div class="tab-pane {if !(isset($pbtab))}active{/if}" id="panel-info">
                        <div class="panel monei-back">
                            <h3>{$display_name|escape:'html':'UTF-8'} {$module_version|escape:'html':'UTF-8'}</h3>
                            <div class="row">
                                <div class="col-md-6">
                                    <p>
                                    <p style="align:center;">
                                        <img style="width:120px" src="https://assets.monei.com/images/logo.svg"
                                            alt="{$display_name|escape:'html':'UTF-8'}">
                                    </p>
                                    <strong>{l s='Grow your business faster with the advanced payment platform' mod='monei'}</strong><br />
                                    </p>
                                </div>
                                <div class="col-md-6">

                                </div>
                            </div>

                            <div class="panel-footer">
                                <a class="btn btn-default btn-secondary"
                                    href="https://support.monei.com/hc/en-us/requests/new?ticket_form_id=360000322338"
                                    target="_blank">
                                    <i class="icon icon-envelope"></i> {l s='Contact support' mod='monei'}
                                </a>
                                <a class="btn btn-default btn-secondary"
                                    href="https://docs.monei.com/docs/e-commerce/prestashop/"
                                    target="_blank">
                                    <i class="icon icon-book"></i> {l s='Documentation' mod='monei'}
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- BASIC CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-1">
                        {$helper_form_1} {* HelperForm, no escaping *}
                    </div>

                    <!-- PAYMENT METHODS CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-2">
                        {$helper_form_2} {* HelperForm, no escaping *}
                    </div>

                    <!-- STATUS CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-3">
                        {$helper_form_3} {* HelperForm, no escaping *}
                    </div>

                    <!-- STYLES CONFIGURATION -->
                    <div class="tab-pane" id="panel-conf-4">
                        {$helper_form_4} {* HelperForm, no escaping *}
                    </div>

                    <!-- END TABS -->
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Override PrestaShop's alert icons for JSON validation feedback */
.json-validation-feedback .alert::before {
    display: none !important;
}

/* Adjust padding since we're removing the icon */
.json-validation-feedback .alert {
    padding-left: 10px !important;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Tab persistence functionality
    const tabsContainer = document.querySelector('#tabs-270581');
    const tabLinks = tabsContainer.querySelectorAll('.nav-tabs a');
    const tabPanes = tabsContainer.querySelectorAll('.tab-pane');
    
    // Get active tab from localStorage or from form submission
    let activeTab = localStorage.getItem('monei_active_tab');
    
    // Check if a specific form was submitted to determine which tab should be active
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('submitMoneiModule')) {
        activeTab = '#panel-conf-1';
    } else if (urlParams.has('submitMoneiModuleGateways')) {
        activeTab = '#panel-conf-2';
    } else if (urlParams.has('submitMoneiModuleStatus')) {
        activeTab = '#panel-conf-3';
    } else if (urlParams.has('submitMoneiModuleComponentStyle')) {
        activeTab = '#panel-conf-4';
    }
    
    // Activate the saved tab if exists
    if (activeTab) {
        // Remove active class from all tabs and panes
        tabLinks.forEach(link => link.parentElement.classList.remove('active'));
        tabPanes.forEach(pane => pane.classList.remove('active'));
        
        // Find and activate the saved tab
        const savedTabLink = document.querySelector('a[href="' + activeTab + '"]');
        if (savedTabLink) {
            savedTabLink.parentElement.classList.add('active');
            const savedTabPane = document.querySelector(activeTab);
            if (savedTabPane) {
                savedTabPane.classList.add('active');
            }
        }
    }
    
    // Save active tab on click
    tabLinks.forEach(function(link) {
        link.addEventListener('click', function() {
            localStorage.setItem('monei_active_tab', this.getAttribute('href'));
        });
    });
    
    // JSON validation for style configuration fields
    const styleFields = [
        'MONEI_CARD_INPUT_STYLE',
        'MONEI_BIZUM_STYLE', 
        'MONEI_PAYMENT_REQUEST_STYLE'
    ];
    
    styleFields.forEach(function(fieldName) {
        const field = document.querySelector('textarea[name="' + fieldName + '"]');
        if (field) {
            // Add real-time validation
            field.addEventListener('blur', function() {
                validateJsonField(this, fieldName);
            });
            
            // Add auto-resize functionality
            field.addEventListener('input', function() {
                autoResizeTextarea(this);
            });
            
            // Add visual feedback container
            const feedbackDiv = document.createElement('div');
            feedbackDiv.className = 'json-validation-feedback';
            feedbackDiv.style.marginTop = '5px';
            field.parentNode.appendChild(feedbackDiv);
            
            // Initial auto-resize
            autoResizeTextarea(field);
        }
    });
    
    function autoResizeTextarea(textarea) {
        // Reset height to auto to get the correct scrollHeight
        textarea.style.height = 'auto';
        
        // Calculate the new height based on scrollHeight
        const newHeight = textarea.scrollHeight;
        
        // Set minimum height (3 rows * estimated line height)
        const minHeight = 60; // Approximately 3 rows
        const maxHeight = 300; // Maximum height to prevent excessive expansion
        
        // Apply the new height within bounds
        textarea.style.height = Math.min(Math.max(newHeight, minHeight), maxHeight) + 'px';
        textarea.style.overflow = newHeight > maxHeight ? 'auto' : 'hidden';
    }
    
    function validateJsonField(field, fieldName) {
        const value = field.value.trim();
        const feedbackDiv = field.parentNode.querySelector('.json-validation-feedback');
        
        // Clear previous feedback
        feedbackDiv.innerHTML = '';
        field.style.borderColor = '';
        
        if (!value) {
            showValidationMessage(feedbackDiv, 'Style configuration cannot be empty.', 'error');
            field.style.borderColor = '#dc3545';
            return false;
        }
        
        try {
            const parsed = JSON.parse(value);
            
            // Basic validation passed - just set green border, no message
            field.style.borderColor = '#28a745';
            
            // Additional specific validations (only show errors)
            if (fieldName === 'MONEI_CARD_INPUT_STYLE') {
                return validateCardInputStyle(parsed, feedbackDiv, field);
            } else if (fieldName === 'MONEI_BIZUM_STYLE') {
                return validateBizumStyle(parsed, feedbackDiv, field);
            } else if (fieldName === 'MONEI_PAYMENT_REQUEST_STYLE') {
                return validatePaymentRequestStyle(parsed, feedbackDiv, field);
            }
            
            return true;
        } catch (e) {
            showValidationMessage(feedbackDiv, 'Invalid JSON: ' + e.message, 'error');
            field.style.borderColor = '#dc3545';
            return false;
        }
    }
    
    function validateCardInputStyle(parsed, feedbackDiv, field) {
        // Only validate for critical errors, don't show warnings for unknown fields
        return true;
    }
    
    function validateBizumStyle(parsed, feedbackDiv, field) {
        if (parsed.height && !isValidHeight(parsed.height)) {
            showValidationMessage(feedbackDiv, 'Height must be a number or include units (px, %)', 'error');
            field.style.borderColor = '#dc3545';
            return false;
        }
        return true;
    }
    
    function validatePaymentRequestStyle(parsed, feedbackDiv, field) {
        if (parsed.height && !isValidHeight(parsed.height)) {
            showValidationMessage(feedbackDiv, 'Height must be a number or include units (px, %)', 'error');
            field.style.borderColor = '#dc3545';
            return false;
        }
        return true;
    }
    
    function isValidHeight(height) {
        return !isNaN(height) || /^\d+(px|%)$/.test(height);
    }
    
    function showValidationMessage(container, message, type) {
        const alertClass = type === 'error' ? 'alert-danger' : 
                          type === 'warning' ? 'alert-warning' : 'alert-success';
        
        const existingAlert = container.querySelector('.alert');
        if (existingAlert) {
            existingAlert.remove();
        }
        
        const alert = document.createElement('div');
        alert.className = 'alert ' + alertClass + ' alert-sm';
        alert.style.padding = '5px 10px';
        alert.style.fontSize = '12px';
        alert.style.marginBottom = '0';
        alert.textContent = message;
        
        container.appendChild(alert);
    }
    
    // Prevent form submission if JSON is invalid
    const form = document.querySelector('form[name="configuration_form"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            let hasErrors = false;
            
            styleFields.forEach(function(fieldName) {
                const field = document.querySelector('textarea[name="' + fieldName + '"]');
                if (field && !validateJsonField(field, fieldName)) {
                    hasErrors = true;
                }
            });
            
            if (hasErrors) {
                e.preventDefault();
                alert('Please fix JSON validation errors before saving.');
            }
        });
    }
});
</script>