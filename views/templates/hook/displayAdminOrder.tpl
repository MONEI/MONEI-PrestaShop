<script src="{$sweetalert2}"></script>

<div class="row">
    <div class="col-lg-12">
        <ul class="nav nav-tabs d-print-none" role="tablist">
            <li class="nav-item">
                <a class="nav-link show active" data-toggle="tab" href="#moneiGeneralTabContent"><i
                        class="material-icons">point_of_sale</i>&nbsp;{l s='Payments' mod='monei'}</a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#moneiRefundsTabContent"><i
                        class="material-icons">remove_shopping_cart</i>&nbsp;{l s='Refunds' mod='monei'}</a>
            </li>
        </ul>
        <div class="tab-content">
            <!-- General Tab -->
            <div class="tab-pane d-print-block show active" id="moneiGeneralTabContent" role="tabpanel">
                <div class="card card-details">
                    <div class="card-header">
                        <h3 class="card-header-title">
                            {l s='MONEI' mod='monei'}
                        </h3>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>{l s='Date' mod='monei'}</th>
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
            <!-- Refunds Tab -->
            <div class="tab-pane d-print-block fade" id="moneiRefundsTabContent" role="tabpanel">
                <div class="card card-details">
                    <div class="card-header">
                        <h3 class="card-header-title">
                            {l s='MONEI' mod='monei'}
                        </h3>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-danger collapse" role="alert" id="moneiAlert">
                            <strong>{l s='Warning' mod='monei'}</strong>
                            {l s='The amount refunded is higher than the payment done. Maximum refundable amount: %s %s' sprintf=[$remainingAmountToRefund / 100|escape:'html':'UTF-8', $currencySymbol|escape:'html':'UTF-8'] mod='monei'}
                        </div>
                        {if $orderTotalPaid == 0}
                            <div class="alert alert-danger" role="alert" id="moneiAlertNotPaid">
                                <strong>{l s='Warning' mod='monei'}</strong>
                                {l s='This order doesn\'t seem to be paid. Be careful with refunds.' mod='monei'}
                            </div>
                        {/if}
                        {if $isRefundable}
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>{l s='Amount to refund' mod='monei'} ({$currencyIso|escape:'html':'UTF-8'}
                                            - {$currencySymbol|escape:'html':'UTF-8'})
                                        </th>
                                        <th>{l s='Reason' mod='monei'}</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <input type="hidden" value="{$orderId|escape:'html':'UTF-8'}"
                                                id="monei_order_id" />
                                            <input type="number" min="0" step=".01" id="monei_refund_amount"
                                                name="monei_refund_amount" required="required"
                                                class="js-comma-transformer form-control" value="0.00"
                                                max="{$remainingAmountToRefund|escape:'html':'UTF-8'}"
                                                data-maxrefund="{$remainingAmountToRefund|escape:'html':'UTF-8'}">
                                        </td>
                                        <td>
                                            <select name="monei_refund_reason" id="monei_refund_reason" required="required"
                                                class="form-control">
                                                <option default value="">{l s='Select a reason' mod='monei'}</option>
                                                <option value="requested_by_customer">
                                                    {l s='Requested by customer' mod='monei'}</option>
                                                <option value="duplicated">{l s='Duplicated' mod='monei'}</option>
                                                <option value="fraudulent">{l s='Fraudulent' mod='monei'}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <button id="moneiBtnRefund" disabled="disabled" type="button"
                                                class="btn btn-primary btn-sm disabled">{l s='Refund' mod='monei'}</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        {else}
                            <div class="alert alert-danger" role="alert" id="moneiAlertRefunded">
                                <strong>{l s='Warning' mod='monei'}</strong>
                                {l s='This order is already fully refunded. You can\'t refund anymore from this panel.' mod='monei'}
                            </div>
                        {/if}

                        <table class="table mt-10">
                            <thead>
                                <tr>
                                    <th>{l s='Date' mod='monei'}</th>
                                    <th>{l s='Amount refunded' mod='monei'}</th>
                                    <th>{l s='Status' mod='monei'}</th>
                                    <th>{l s='Status Message' mod='monei'}</th>
                                    <th>{l s='Employee' mod='monei'}</th>
                                    <th>{l s='Live' mod='monei'}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach from=$paymentRefundLogs item=paymentRefund}
                                    <tr>
                                        <td>{$paymentRefund.date_add|escape:'html':'UTF-8'}</td>
                                        <td>{$paymentRefund.amountFormatted|escape:'html':'UTF-8'}</td>
                                        <td>{$paymentRefund.paymentHistory.status|escape:'html':'UTF-8'}</td>
                                        <td>{if isset($paymentRefund.paymentHistory.responseDecoded.statusMessage) and $paymentRefund.paymentHistory.responseDecoded.statusMessage}{$paymentRefund.paymentHistory.responseDecoded.statusMessage|escape:'html':'UTF-8'}{else}-{/if}
                                        </td>
                                        <td>{if isset($paymentRefund.paymentHistory.responseDecoded.livemode) and $paymentRefund.paymentHistory.responseDecoded.livemode}Yes{else}No{/if}
                                        <td>{$paymentRefund.id_employee|escape:'html':'UTF-8'}</td>
                                        <td class="text-right">
                                            <a class="fancybox"
                                                data-moneijson="{$paymentRefund.paymentHistory.responseB64|escape:'html':'UTF-8'}"><span
                                                    class="btn btn-primary">{l s='Details...' mod='monei'}</span></a>
                                        </td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                        {if $totalRefundedAmount > 0}
                            <div class="alert alert-info" role="alert" id="moneiAlertTotalRefunded">
                                <strong>{l s='Total refunds:' mod='monei'}</strong>
                                {$totalRefundedAmountFormatted|escape:'html':'UTF-8'}
                            </div>
                        {/if}
                    </div>
                </div>
            </div>
        </div>
</div>