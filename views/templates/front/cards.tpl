

{extends file='customer/page.tpl'}

{block name='page_header_container'}
    <header class="page-header">
        <h1 class="h1 page-title"><span>{l s='My Credit Cards' mod='monei'}</span></h1>
    </header>
{/block}

{block name='page_title'}
    {l s='My Credit Cards' mod='monei'}
{/block}

{block name='page_content'}
    <div class="tab-pane" id="pane_monei_cards" role='tabpanel'>
        <div class="table-responsive">
            <table class="table table-striped table-hover" id="monei_cards">
                <thead class="thead-dark">
                <tr>
                    <th>{l s='#' mod='monei'}</th>
                    <th>{l s='Brand' mod='monei'}</th>
                    <th>{l s='3D Secure' mod='monei'}</th>
                    <th>{l s='Numbers' mod='monei'}</th>
                    <th>{l s='Expiration' mod='monei'}</th>
                    <th>{l s='Date add' mod='monei'}</th>
                    <th class="action"><i class="fa fa-eye"></i></th>
                </tr>
                </thead>
                <tbody id="credit_card_list">
                {foreach from=$monei_cards item=monei_card}
                    <tr>
                        <td>{$monei_card.id_monei_tokens|escape:'html':'UTF-8'}</td>
                        <td>
                            <img width="48"
                                 src="{$modules_dir|escape:'html':'UTF-8'}/monei/views/img/{$monei_card.brand|escape:'html':'UTF-8'}.png"
                                 class="img img-responsive" alt="{$monei_card.brand|escape:'html':'UTF-8'}">
                        </td>
                        <td class="text-center">
                            {if $monei_card.threeDS == 0}
                                <span class="material-icons text-danger">block</span>
                            {else}
                                <span class="material-icons text-success">verified_user</span>
                            {/if}
                        </td>
                        <td>**** **** **** {$monei_card.last_four|escape:'html':'UTF-8'}</td>
                        <td>{$monei_card.expiration|date_format:'%m/%y'|escape:'html':'UTF-8'}</td>
                        <td>{$monei_card.date_add|escape:'html':'UTF-8'}</td>
                        <td class="action">
                            <a href="#" class="btn btn-danger btn-sm"
                               data-monei-card="{$monei_card.id_monei_tokens|escape:'html':'UTF-8'}">
                                {if !$is_warehouse}
                                    <i class="material-icons">delete</i>
                                {else}
                                    <i class="fa fa-trash"></i>
                                {/if}
                            </a>
                        </td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
{/block}