<script src="{$sweetalert2}"></script>

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
                            <p class="mb-3">{l s='Authorized amount:' mod='monei'} <strong>{$authorizedAmountFormatted}</strong></p>
                            {if isset($capturedAmount) && $capturedAmount > 0}
                                <p class="mb-3">{l s='Already captured:' mod='monei'} <strong>{$capturedAmountFormatted}</strong></p>
                                <p class="mb-3">{l s='Remaining capturable:' mod='monei'} <strong>{$remainingAmountFormatted}</strong></p>
                            {/if}
                            <button type="button" class="btn btn-primary" id="monei-capture-payment-btn" data-order-id="{$orderId|escape:'html':'UTF-8'}" data-max-amount="{$remainingAmount|escape:'html':'UTF-8'}" data-currency-sign="{$currencySign|escape:'html':'UTF-8'}">
                                <i class="material-icons">payment</i> {l s='Capture Payment' mod='monei'}
                            </button>
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
<style>
    .swal2-popup .input-group {
        display: flex;
        justify-content: center;
        align-items: center;
        margin: 10px auto;
    }
    .swal2-popup .input-group-append {
        display: flex;
    }
    .swal2-popup .input-group-text {
        padding: 0.375rem 0.75rem;
        background-color: #e9ecef;
        border: 1px solid #ced4da;
        border-left: 0;
        border-radius: 0 0.25rem 0.25rem 0;
        height: 38px;
        display: flex;
        align-items: center;
    }
    .swal2-popup #swal-capture-amount {
        border-radius: 0.25rem 0 0 0.25rem !important;
        margin-right: 0 !important;
    }
</style>
<script>
    $(document).ready(function() {
        $('#monei-capture-payment-btn').on('click', function() {
            var $btn = $(this);
            var orderId = $btn.data('order-id');
            var maxAmount = parseFloat($btn.data('max-amount'));
            var currencySign = $btn.data('currency-sign');
            
            Swal.fire({
                title: '{l s='Capture Payment' mod='monei' js=1}',
                html: '<div class="form-group">' +
                      '<label for="swal-capture-amount">{l s='Amount to capture:' mod='monei' js=1}</label>' +
                      '<div class="input-group">' +
                      '<input type="number" id="swal-capture-amount" class="form-control swal2-input" ' +
                      'value="' + maxAmount.toFixed(2) + '" ' +
                      'min="0.01" max="' + maxAmount.toFixed(2) + '" step="0.01" ' +
                      'style="max-width: 200px; margin: 0 auto;">' +
                      '<div class="input-group-append">' +
                      '<span class="input-group-text">' + currencySign + '</span>' +
                      '</div>' +
                      '</div>' +
                      '<small class="form-text text-muted">{l s='Maximum:' mod='monei' js=1} ' + 
                      new Intl.NumberFormat('{$locale|escape:'javascript':'UTF-8'}', {
                          style: 'currency',
                          currency: '{$currencyCode|escape:'javascript':'UTF-8'}'
                      }).format(maxAmount) + '</small>' +
                      '</div>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: '{l s='Capture payment' mod='monei' js=1}',
                cancelButtonText: '{l s='Cancel' mod='monei' js=1}',
                showLoaderOnConfirm: true,
                preConfirm: function() {
                    var captureAmount = parseFloat($('#swal-capture-amount').val());
                    
                    // Validate amount
                    if (isNaN(captureAmount) || captureAmount <= 0) {
                        Swal.showValidationMessage('{l s='Please enter a valid amount to capture.' mod='monei' js=1}');
                        return false;
                    }
                    
                    if (captureAmount > maxAmount) {
                        Swal.showValidationMessage('{l s='The amount cannot exceed the remaining capturable amount.' mod='monei' js=1}');
                        return false;
                    }
                    
                    return $.ajax({
                        url: '{$captureLinkController|escape:'javascript':'UTF-8'}',
                        type: 'POST',
                        data: {
                            ajax: 1,
                            action: 'capturePayment',
                            id_order: orderId,
                            amount: captureAmount
                        },
                        dataType: 'json'
                    }).then(function(response) {
                        if (!response.success) {
                            throw new Error(response.message || '{l s='An error occurred while capturing the payment' mod='monei' js=1}');
                        }
                        return response;
                    }).catch(function(error) {
                        Swal.showValidationMessage(error.message || '{l s='Request failed' mod='monei' js=1}');
                    });
                },
                allowOutsideClick: function() { return !Swal.isLoading(); }
            }).then(function(result) {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: '{l s='Success!' mod='monei' js=1}',
                        text: '{l s='Payment has been captured successfully.' mod='monei' js=1}',
                        icon: 'success'
                    }).then(function() {
                        location.reload();
                    });
                }
            });
        });
    });
</script>
{/if}