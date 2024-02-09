{include file="./scripts/admin_scripts.tpl"}

<div class="col-lg-12">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-credit-card"></i> {l s='MONEI' mod='monei'}
        </div>
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item active">
                <a href="#moneiGeneralTabContent" role="tab" data-toggle="tab">
                    <i class="icon-dashboard"></i>
                    {l s='Payments' mod='monei'}
                </a>
            </li>
            <li class="nav-item">
                <a href="#moneiRefundsTabContent" role="tab" data-toggle="tab">
                    <i class="icon-reply"></i>
                    {l s='Refunds' mod='monei'}
                </a>
            </li>
        </ul>
        <div class="tab-content panel">
            <!-- General -->
            <div class="tab-pane active" id="moneiGeneralTabContent" role="tabpanel">
                <div class="table-responsive">
                    <table class="table" id="moneiHistory">
                        <thead>
                        <tr>
                            <th><span class="title_box">{l s='Date' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='Status Code' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='Status' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='Status Message' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='MONEI Callback' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='IP' mod='monei'}</span></th>
                            <th><span class="title_box">{l s='Live' mod='monei'}</span></th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        {foreach from=$history_logs item=hl}
                            <tr class="product-line-row">
                                <td>
                                    {$hl.date_add|escape:'html':'UTF-8'}
                                </td>
                                <td>
                                    {if $hl.status_code}{$hl.status_code|escape:'html':'UTF-8'}{else}-{/if}
                                </td>
                                <td>
                                        <span
                                                class="badge rounded badge-print-light badge-{$hl.badge|escape:'html':'UTF-8'} text-white font-bold">{if $hl.status}{$hl.status|escape:'html':'UTF-8'}{else}-{/if}</span>
                                </td>
                                <td>
                                    {if $hl.status_message}{$hl.status_message|escape:'html':'UTF-8'}{else}-{/if}
                                </td>
                                <td>
                                    {if $hl.is_callback}
                                        <span class="badge badge-success">&nbsp;</span>
                                    {else}
                                        <span class="badge badge-danger">&nbsp;</span>
                                    {/if}
                                </td>
                                <td>
                                    {if $hl.session_details.ip}{$hl.session_details.ip|escape:'html':'UTF-8'}{else}-{/if}
                                </td>
                                <td>{if $hl.livemode}
                                        <span class="badge badge-success">&nbsp;</span>
                                    {else}
                                        <span class="badge badge-danger">&nbsp;</span>
                                    {/if}
                                </td>
                                <td class="text-right">
                                    <a class="fancybox" data-moneijson="{$hl.b64|escape:'html':'UTF-8'}"><span
                                                class="btn btn-primary">{l s='Details...' mod='monei'}</span></a>
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
            <!-- Refunds -->
            <div class="tab-pane" id="moneiRefundsTabContent" role="tabpanel">
                <div class="alert alert-danger collapse" role="alert" id="moneiAlert">
                    <strong>{l s='Warning' mod='monei'}</strong> {l s='The amount refunded is higher than the payment done.' mod='monei'}
                </div>
                {if $amount_paid == 0}
                    <div class="alert alert-danger" role="alert" id="moneiAlertNotPaid">
                        <strong>{l s='Warning' mod='monei'}</strong> {l s='This order doesn\'t seems to be paid. Be careful with refunds.' mod='monei'}
                    </div>
                {/if}
                <div class="table-responsive">
                    {if $is_refundable}
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{l s='Amount to refund' mod='monei'} ({$currency_iso|escape:'html':'UTF-8'}
                                    - {$currency_symbol|escape:'html':'UTF-8'})
                                </th>
                                </th>
                                <th>{l s='Reason' mod='monei'}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>
                                    <input type="hidden" value="{$id_order|escape:'html':'UTF-8'}" id="monei_order_id"/>
                                    <input type="number" min="0" step=".01" id="monei_refund_amount"
                                           name="monei_refund_amount" required="required"
                                           class="js-comma-transformer form-control" value="0.00"
                                           data-maxrefund="{$max_amount|escape:'html':'UTF-8'}">
                                </td>
                                <td>
                                    <select name="refund_reason" required="required" class="form-control"
                                            id="monei_refund_reason">
                                        <option default value="">{l s='Select a reason' mod='monei'}</option>
                                        <option value="requested_by_customer">{l s='Requested by customer' mod='monei'}</option>
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
                            <strong>{l s='Warning' mod='monei'}</strong> {l s='This order is already fully refunded. You can\'t refund anymore from this panel.' mod='monei'}
                        </div>
                    {/if}
                    <table class="table" id="moneiRefunds" style="margin-top: 20px">
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
                        {foreach from=$refund_logs item=rf}
                            <tr class="product-line-row">
                                <td>{$rf.date_add|escape:'html':'UTF-8'}</td>
                                <td>{$rf.amount|escape:'html':'UTF-8'}</td>
                                <td><span
                                            class="badge rounded badge-print-light badge-{$rf.badge|escape:'html':'UTF-8'} text-dark">{if $rf.status}{$rf.status|escape:'html':'UTF-8'}{else}-{/if}</span>
                                </td>
                                <td>{if $rf.status_message}{$rf.status_message|escape:'html':'UTF-8'}{else}-{/if}
                                </td>
                                <td>{$rf.employee|escape:'html':'UTF-8'}</td>
                                <td>{if $rf.livemode}
                                        <span class="badge badge-success">&nbsp;</span>
                                    {else}
                                        <span class="badge badge-danger">&nbsp;</span>
                                    {/if}</td>
                                <td class="text-right">
                                    <a class="fancybox" data-moneijson="{$rf.b64|escape:'html':'UTF-8'}"><span
                                                class="btn btn-primary">{l s='Details...' mod='monei'}</span></a>
                                </td>
                            </tr>
                        {/foreach}
                        </tbody>
                    </table>
                    {if $amount_refunded > 0}
                        <div class="alert alert-info" role="alert" id="moneiAlertTotalRefunded">
                            <strong>{l s='Total refunds:' mod='monei'}</strong> {$amount_refunded_formatted|escape:'html':'UTF-8'}
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>