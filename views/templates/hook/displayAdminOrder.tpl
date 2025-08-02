<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h3 class="card-header-title">
                    <i class="material-icons">payment</i>&nbsp;{l s='MONEI Payments' mod='monei'}
                </h3>
            </div>
            <div class="card-body">
                    {if $isCapturable}
                        <div class="alert alert-warning mb-3">
                            <p class="mb-2"><strong>{l s='Payment Authorized' mod='monei'}</strong></p>
                            <p class="mb-2">{l s='The payment has been authorized but not yet captured. The funds are reserved on the customer\'s card but have not been transferred to your account.' mod='monei'}</p>
                            <p class="mb-3">{l s='You must capture the payment within 7 days or the authorization will expire and the funds will be released back to the customer.' mod='monei'}</p>
                            <p class="mb-3">{l s='Authorized amount:' mod='monei'} <strong>{$authorizedAmountFormatted}</strong></p>
                            {if isset($capturedAmount) && $capturedAmount > 0}
                                <p class="mb-3">{l s='Already captured:' mod='monei'} <strong>{$capturedAmountFormatted}</strong></p>
                                <p class="mb-3">{l s='Remaining capturable:' mod='monei'} <strong>{$remainingAmountFormatted}</strong></p>
                            {/if}
                            <div>
                                <button type="button" class="btn btn-primary" id="monei-capture-payment-btn" data-order-id="{$orderId|escape:'html':'UTF-8'}" data-max-amount="{$remainingAmount|escape:'html':'UTF-8'}" data-currency-sign="{$currencySign|escape:'html':'UTF-8'}" data-toggle="modal" data-target="#moneiCaptureModal">
                                    <i class="material-icons">payment</i> {l s='Capture Payment' mod='monei'}
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
                                            <span class="d-inline-flex align-items-center">
                                                <span
                                                    style="width: 20px; height: 20px; display: inline-block; margin-right: 5px;">
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
                                    <td>{if isset($paymentHistory.responseDecoded.statusMessage) and $paymentHistory.responseDecoded.statusMessage}{$paymentHistory.responseDecoded.statusMessage|escape:'html':'UTF-8'}{else}-{/if}
                                    </td>
                                    <td>{if isset($paymentHistory.responseDecoded.traceDetails.ip) and $paymentHistory.responseDecoded.traceDetails.ip}{$paymentHistory.responseDecoded.traceDetails.ip|escape:'html':'UTF-8'}{else}-{/if}
                                    </td>
                                    <td>{if isset($paymentHistory.responseDecoded.livemode) and $paymentHistory.responseDecoded.livemode}Yes{else}No{/if}
                                    </td>
                                    <td class="text-right">
                                        <a class="fancybox"
                                            data-moneijson="{$paymentHistory.responseB64|escape:'html':'UTF-8'}"><span
                                                class="btn btn-primary">{l s='Details...' mod='monei'}</span></a>
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
{* Bootstrap Modal for Capture Payment *}
<div class="modal fade" id="moneiCaptureModal" tabindex="-1" role="dialog" aria-labelledby="moneiCaptureModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="moneiCaptureModalLabel">{l s='Capture Payment' mod='monei'}</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
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
                            <div class="input-group-append">
                                <span class="input-group-text">{$currencySign}</span>
                            </div>
                        </div>
                        <small class="form-text text-muted">
                            {l s='Maximum:' mod='monei'} {$remainingAmountFormatted}
                        </small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-outline-secondary" data-dismiss="modal">{l s='Cancel' mod='monei'}</button>
                <button type="button" class="btn btn-primary" id="confirm-capture-btn">
                    {l s='Capture payment' mod='monei'}
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    $(document).ready(function() {
        var orderId = $('#monei-capture-payment-btn').data('order-id');
        var maxAmount = parseFloat($('#monei-capture-payment-btn').data('max-amount'));
        
        // Handle capture confirmation
        $('#confirm-capture-btn').on('click', function() {
            var $btn = $(this);
            var captureAmount = parseFloat($('#capture-amount').val());
            
            // Validate amount
            if (isNaN(captureAmount) || captureAmount <= 0) {
                alert('{l s='Please enter a valid amount to capture.' mod='monei' js=1}');
                return;
            }
            
            if (captureAmount > maxAmount) {
                alert('{l s='The amount cannot exceed the remaining capturable amount.' mod='monei' js=1}');
                return;
            }
            
            // Disable button and show loading
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> {l s='Processing...' mod='monei' js=1}');
            
            // Make AJAX request
            $.ajax({
                url: '{$captureLinkController|escape:'javascript':'UTF-8'}',
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
                        // Show success message using PrestaShop's native growl notification
                        if (typeof showSuccessMessage === 'function') {
                            showSuccessMessage('{l s='Payment has been captured successfully.' mod='monei' js=1}');
                        }
                        // Reload page after a short delay
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        alert(response.message || '{l s='An error occurred while capturing the payment' mod='monei' js=1}');
                        $btn.prop('disabled', false).html('{l s='Capture payment' mod='monei' js=1}');
                    }
                },
                error: function() {
                    alert('{l s='An unexpected error occurred. Please try again.' mod='monei' js=1}');
                    $btn.prop('disabled', false).html('{l s='Capture payment' mod='monei' js=1}');
                }
            });
        });
        
        // Reset button state when modal is closed
        $('#moneiCaptureModal').on('hidden.bs.modal', function() {
            $('#confirm-capture-btn').prop('disabled', false).html('{l s='Capture payment' mod='monei' js=1}');
        });
        
    });
</script>
{/if}