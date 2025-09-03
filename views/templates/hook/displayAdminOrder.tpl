<div class="row">
    <div class="col-lg-12">
        <div class="panel card">
            <div class="panel-heading card-header">
                <h3 class="panel-title card-header-title">
                    <i class="icon-money material-icons">payment</i> {l s='MONEI Payments' mod='monei'}
                </h3>
            </div>
            <div class="panel-body card-body">
                {if $isCapturable}
                    <div class="alert alert-warning mb-3">
                        <p class="mb-2"><strong>{l s='Payment Authorized' mod='monei'}</strong></p>
                        <p class="mb-2">{l s='The payment has been authorized but not yet captured.' mod='monei'}</p>
                        <p class="mb-3">{l s='Authorized amount:' mod='monei'} <strong>{$authorizedAmountFormatted}</strong></p>
                        <p class="mb-3">{l s='Remaining capturable:' mod='monei'} <strong>{$remainingAmountFormatted}</strong></p>
                        <div>
                            <button type="button" class="btn btn-warning btn-primary" onclick="alert('Capture payment')">
                                {l s='Capture Payment' mod='monei'}
                            </button>
                        </div>
                    </div>
                {/if}
                
                <h4>{l s='Payment History' mod='monei'}</h4>
                <table class="table">
                    <thead>
                        <tr>
                            <th>{l s='Date' mod='monei'}</th>
                            <th>{l s='Status' mod='monei'}</th>
                            <th>{l s='Status Code' mod='monei'}</th>
                            <th>{l s='Payment Method' mod='monei'}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach from=$paymentHistoryLogs item=history}
                        <tr>
                            <td>{$history.date_add}</td>
                            <td>{$history.status}</td>
                            <td>{$history.status_code}</td>
                            <td>
                                {if isset($history.paymentDetails) && $history.paymentDetails}
                                    {$history.paymentDetails.method_display}
                                {else}
                                    -
                                {/if}
                            </td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>