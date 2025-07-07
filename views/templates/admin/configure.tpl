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
                                <a class="btn btn-default"
                                    href="https://support.monei.com/hc/en-us/requests/new?ticket_form_id=360000322338"
                                    target="_blank">
                                    <i class="icon icon-envelope"></i> {l s='Contact support' mod='monei'}
                                </a>
                                <a class="btn btn-default"
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
                        <div class="alert alert-warning" role="alert">
                            <p>
                                {l s='Please ensure your payment methods are activated in your'} <a href="https://dashboard.monei.com/settings/payment-methods" target="_blank">{l s='MONEI dashboard' mod='monei'}</a> {l s='before configuring them here.' mod='monei'}
                            </p>
                        </div>
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
            
            // Add visual feedback container
            const feedbackDiv = document.createElement('div');
            feedbackDiv.className = 'json-validation-feedback';
            feedbackDiv.style.marginTop = '5px';
            field.parentNode.appendChild(feedbackDiv);
        }
    });
    
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