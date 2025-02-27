{extends file='customer/page.tpl'}

{block name='page_header_container'}
    <header class="page-header">
        <h1 class="h1 page-title">{l s='My Credit Cards' mod='monei'}</h1>
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
                        <th>{l s='Numbers' mod='monei'}</th>
                        <th>{l s='Expiration' mod='monei'}</th>
                        <th>{l s='Date Added' mod='monei'}</th>
                        <th class="action"><i class="fa fa-eye"></i></th>
                    </tr>
                </thead>
                <tbody id="credit_card_list">
                    {foreach from=$customerCardsList item=customerCard}
                        <tr>
                            <td>{$customerCard.id|escape:'html':'UTF-8'}</td>
                            <td>
                                <img width="48"
                                     src="{$modules_dir|escape:'html':'UTF-8'}/monei/views/img/payments/{$customerCard.brand|escape:'html':'UTF-8'}.svg"
                                     class="img img-responsive" alt="{$customerCard.brand|escape:'html':'UTF-8'}">
                            </td>
                            <td>{$customerCard.lastFourWithMask|escape:'html':'UTF-8'}</td>
                            <td>{$customerCard.expiration|date_format:'%m/%y'|escape:'html':'UTF-8'}</td>
                            <td>{$customerCard.dateAdd|escape:'html':'UTF-8'}</td>
                            <td class="action">
                                <a href="#" class="btn btn-danger btn-sm" data-customer-card-id="{$customerCard.id|escape:'html':'UTF-8'}">
                                    {if !$isWarehouseInstalled}
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