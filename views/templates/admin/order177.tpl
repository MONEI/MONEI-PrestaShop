{include file="./scripts/admin_scripts.tpl"}

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
                                <th>{l s='MONEI Callback' mod='monei'}</th>
                                <th>{l s='IP' mod='monei'}</th>
                                <th>{l s='Live' mod='monei'}</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            {foreach from=$history_logs item=hl}
                                <tr>
                                    <td>{$hl.date_add|escape:'html':'UTF-8'}</td>
                                    <td>{if isset($hl.status_code) and $hl.status_code}{$hl.status_code|escape:'html':'UTF-8'}{else}-{/if}</td>
                                    <td><span
                                                class="badge rounded badge-print-light bg-{$hl.badge|escape:'html':'UTF-8'} text-white font-bold">{if isset($hl.status) and $hl.status}{$hl.status|escape:'html':'UTF-8'}{else}-{/if}</span>
                                    </td>
                                    <td>{if isset($hl.status_message) and $hl.status_message}{$hl.status_message|escape:'html':'UTF-8'}{else}-{/if}
                                    </td>
                                    <td>{if $hl.is_callback}
                                            <span class="badge bg-success">&nbsp;</span>
                                        {else}
                                            <span class="badge bg-danger">&nbsp;</span>
                                        {/if}</td>
                                    <td>{if isset($hl.session_details) and $hl.session_details.ip}{$hl.session_details.ip|escape:'html':'UTF-8'}{else}-{/if}
                                    </td>
                                    <td>{if isset($rf.livemode) and $hl.livemode}
                                            <span class="badge bg-success">&nbsp;</span>
                                        {else}
                                            <span class="badge bg-danger">&nbsp;</span>
                                        {/if}</td>
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
                            <strong>{l s='Warning' mod='monei'}</strong> {l s='The amount refunded is higher than the payment done.' mod='monei'}
                        </div>
                        {if $amount_paid == 0}
                            <div class="alert alert-danger" role="alert" id="moneiAlertNotPaid">
                                <strong>{l s='Warning' mod='monei'}</strong> {l s='This order doesn\'t seems to be paid. Be careful with refunds.' mod='monei'}
                            </div>
                        {/if}
                        {if $is_refundable}
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>{l s='Amount to refund' mod='monei'} ({$currency_iso|escape:'html':'UTF-8'}
                                        - {$currency_symbol|escape:'html':'UTF-8'})
                                    </th>
                                    <th>{l s='Reason' mod='monei'}</th>
                                    <th></th>
                                </tr>
                                </thead>
                                <tbody>
                                <tr>
                                    <td>
                                        <input type="hidden" value="{$id_order|escape:'html':'UTF-8'}"
                                               id="monei_order_id"/>
                                        <input type="number" min="0" step=".01" id="monei_refund_amount"
                                               name="monei_refund_amount" required="required"
                                               class="js-comma-transformer form-control" value="0.00"
                                               data-maxrefund="{$max_amount|escape:'html':'UTF-8'}">
                                    </td>
                                    <td>
                                        <select name="monei_refund_reason" id="monei_refund_reason" required="required"
                                                class="form-control" id="monei_refund_reason">
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
                            {foreach from=$refund_logs item=rf}
                                <tr>
                                    <td>{$rf.date_add|escape:'html':'UTF-8'}</td>
                                    <td>{$rf.amount|escape:'html':'UTF-8'}</td>
                                    <td><span
                                                class="badge rounded badge-print-light bg-{$rf.badge|escape:'html':'UTF-8'} text-dark">{if isset($rf.status) and $rf.status}{$rf.status|escape:'html':'UTF-8'}{else}-{/if}</span>
                                    </td>
                                    <td>{if isset($rf.status_message) and $rf.status_message}{$rf.status_message|escape:'html':'UTF-8'}{else}-{/if}
                                    </td>
                                    <td>{$rf.employee|escape:'html':'UTF-8'}</td>
                                    <td>{if isset($rf.livemode) and $rf.livemode}
                                            <span class="badge bg-success">&nbsp;</span>
                                        {else}
                                            <span class="badge bg-danger">&nbsp;</span>
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