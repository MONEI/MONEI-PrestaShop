<div class="row">
    <div class="col-lg-12">
        <div class="panel card">
            <div class="panel-heading card-header">
                <i class="icon-credit-card"></i> {l s='MONEI Payments' mod='monei'}
            </div>
            <div class="panel-body card-body">
                {if $isCapturable}
                    <div class="alert alert-warning mb-3">
                        <p class="mb-2"><strong>{l s='Payment Authorized' mod='monei'}</strong></p>
                        <p class="mb-2">{l s='The payment has been authorized but not yet captured. The funds are reserved on the customer\'s card but have not been transferred to your account.' mod='monei'}</p>
                        <p class="mb-3">{l s='You must capture the payment within 7 days or the authorization will expire and the funds will be released back to the customer.' mod='monei'}</p>
                        <p class="mb-3">{l s='Authorized amount:' mod='monei'} <strong>{$authorizedAmountFormatted}</strong></p>
                        <div style="margin-top: 15px;">
                            <button type="button" class="btn btn-warning" id="monei-capture-payment-btn" 
                                data-order-id="{$orderId|escape:'html':'UTF-8'}" 
                                data-max-amount="{$remainingAmount|escape:'html':'UTF-8'}" 
                                data-currency-sign="{$currencySign|escape:'html':'UTF-8'}" 
                                {$modalToggle}="modal" 
                                {$modalTarget}="#moneiCaptureModal">
                                <i class="icon-check"></i> {l s='Capture Payment' mod='monei'}
                            </button>
                        </div>
                    </div>
                {/if}
                
                {* Payment History Table *}
                <table class="table">
                    <thead>
                        <tr>
                            <th>{l s='Date' mod='monei'}</th>
                            <th>{l s='Payment Method' mod='monei'}</th>
                            <th>{l s='Status Code' mod='monei'}</th>
                            <th>{l s='Status' mod='monei'}</th>
                            <th>{l s='Status Message' mod='monei'}</th>
                            <th>{l s='IP' mod='monei'}</th>
                            <th>{l s='Live' mod='monei'}</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$paymentHistoryLogs item=paymentHistory}
                            <tr>
                                <td>{$paymentHistory.date_add|escape:'html':'UTF-8'}</td>
                                <td>
                                    {if $paymentHistory.paymentDetails}
                                        <span class="d-inline-flex align-items-center" style="display: inline-flex; align-items: center;">
                                            <span style="display: inline-block; margin-right: 5px;">
                                                {$paymentHistory.paymentDetails.icon nofilter}
                                            </span>
                                            {$paymentHistory.paymentDetails.method_display|escape:'html':'UTF-8'}
                                        </span>
                                    {else}
                                        -
                                    {/if}
                                </td>
                                <td>{$paymentHistory.status_code|escape:'html':'UTF-8'}</td>
                                <td>{$paymentHistory.status|escape:'html':'UTF-8'}</td>
                                <td>{if isset($paymentHistory.responseDecoded.statusMessage) and $paymentHistory.responseDecoded.statusMessage}{$paymentHistory.responseDecoded.statusMessage|escape:'html':'UTF-8'}{else}-{/if}</td>
                                <td>{if isset($paymentHistory.responseDecoded.traceDetails.ip) and $paymentHistory.responseDecoded.traceDetails.ip}{$paymentHistory.responseDecoded.traceDetails.ip|escape:'html':'UTF-8'}{else}-{/if}</td>
                                <td>{if isset($paymentHistory.responseDecoded.livemode) and $paymentHistory.responseDecoded.livemode}Yes{else}No{/if}</td>
                                <td class="text-right">
                                    <a class="fancybox" data-moneijson="{$paymentHistory.responseB64|escape:'html':'UTF-8'}">
                                        <span class="btn btn-primary">{l s='Details...' mod='monei'}</span>
                                    </a>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{if $isCapturable}
{* Bootstrap Modal for Capture Payment - Compatible with BS3 and BS4 *}
<div class="modal fade" id="moneiCaptureModal" tabindex="-1" role="dialog" aria-labelledby="moneiCaptureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title h5" id="moneiCaptureModalLabel">{l s='Capture Payment' mod='monei'}</h4>
                <button type="button" class="close" {$modalDismiss}="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <p>{l s='Important: You can only capture once. If you capture a partial amount, the remaining authorization will be released.' mod='monei'}</p>
                </div>
                <form id="monei-capture-form">
                    <div class="form-group">
                        <label for="capture-amount">{l s='Amount to capture' mod='monei'}</label>
                        <div class="input-group">
                            <input type="number" 
                                   class="form-control" 
                                   id="capture-amount" 
                                   name="amount"
                                   value="{$remainingAmount|string_format:"%.2f"}" 
                                   min="0.01" 
                                   max="{$remainingAmount|string_format:"%.2f"}" 
                                   step="0.01"
                                   required>
                            <div class="input-group-addon input-group-append">
                                <span class="input-group-text">{$currencySign}</span>
                            </div>
                        </div>
                        <small class="help-block form-text text-muted">
                            {l s='Maximum authorized amount:' mod='monei'} {$authorizedAmountFormatted}
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-secondary" {$modalDismiss}="modal">
                    {l s='Cancel' mod='monei'}
                </button>
                <button type="button" class="btn btn-primary" id="confirm-capture-btn">
                    <i class="icon-check"></i> {l s='Capture payment' mod='monei'}
                </button>
            </div>
        </div>
    </div>
</div>

{literal}
<script>
    $(document).ready(function() {
        var orderId = $('#monei-capture-payment-btn').data('order-id');
        var maxAmount = parseFloat($('#monei-capture-payment-btn').data('max-amount'));
        var currencySign = $('#monei-capture-payment-btn').data('currency-sign');
        
        // Handle capture confirmation
        $('#confirm-capture-btn').on('click', function() {
            var $btn = $(this);
            var originalHtml = $btn.html();
            var captureAmount = parseFloat($('#capture-amount').val());
            
            // Validate amount
            if (isNaN(captureAmount) || captureAmount <= 0) {
                alert({/literal}'{l s='Please enter a valid amount to capture.' mod='monei' js=1}'{literal});
                return;
            }
            
            if (captureAmount > maxAmount) {
                alert({/literal}'{l s='The amount cannot exceed the remaining capturable amount.' mod='monei' js=1}'{literal});
                return;
            }
            
            // Disable button and show loading
            $btn.prop('disabled', true);
            // Use icon-refresh for PS 1.7.2 compatibility (no spinner-border in Bootstrap 3)
            $btn.html('<i class="icon-refresh icon-spin"></i> {/literal}{l s='Processing...' mod='monei' js=1}{literal}');
            
            // Make AJAX request
            $.ajax({
                url: {/literal}'{$captureLinkController|escape:'javascript':'UTF-8'}'{literal},
                type: 'POST',
                data: {
                    ajax: 1,
                    action: 'capturePayment',
                    id_order: orderId,
                    amount: captureAmount
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $('#moneiCaptureModal').modal('hide');
                        
                        // Show success message using PrestaShop's notification system
                        if (typeof showSuccessMessage === 'function') {
                            showSuccessMessage({/literal}'{l s='Payment has been captured successfully.' mod='monei' js=1}'{literal});
                        } else if (typeof $.growl !== 'undefined') {
                            // PS 1.7.8+ growl notifications
                            $.growl.notice({
                                title: '',
                                message: {/literal}'{l s='Payment has been captured successfully.' mod='monei' js=1}'{literal}
                            });
                        } else {
                            // Fallback alert
                            alert({/literal}'{l s='Payment has been captured successfully.' mod='monei' js=1}'{literal});
                        }
                        
                        // Reload page after a short delay
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        var errorMsg = response.message || {/literal}'{l s='An error occurred while capturing the payment' mod='monei' js=1}'{literal};
                        
                        if (typeof showErrorMessage === 'function') {
                            showErrorMessage(errorMsg);
                        } else if (typeof $.growl !== 'undefined') {
                            $.growl.error({
                                title: '',
                                message: errorMsg
                            });
                        } else {
                            alert(errorMsg);
                        }
                        
                        $btn.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr, status, error) {
                    var errorMsg = {/literal}'{l s='An unexpected error occurred. Please try again.' mod='monei' js=1}'{literal};
                    
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    
                    if (typeof showErrorMessage === 'function') {
                        showErrorMessage(errorMsg);
                    } else if (typeof $.growl !== 'undefined') {
                        $.growl.error({
                            title: '',
                            message: errorMsg
                        });
                    } else {
                        alert(errorMsg);
                    }
                    
                    $btn.prop('disabled', false).html(originalHtml);
                }
            });
        });
        
        // Reset button state when modal is closed
        $('#moneiCaptureModal').on('hidden.bs.modal', function() {
            var originalHtml = '<i class="icon-check"></i> {/literal}{l s='Capture payment' mod='monei' js=1}{literal}';
            $('#confirm-capture-btn').prop('disabled', false).html(originalHtml);
        });
    });
</script>
{/literal}
{/if}