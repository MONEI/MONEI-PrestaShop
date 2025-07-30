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