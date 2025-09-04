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
                    {if empty($customerCardsList)}
                        <tr>
                            <td colspan="6" class="text-center">
                                {l s='You don\'t have any saved credit cards yet.' mod='monei'}
                            </td>
                        </tr>
                    {else}
                        {foreach from=$customerCardsList item=customerCard}
                            <tr>
                                <td>{$customerCard.id|escape:'html':'UTF-8'}</td>
                                <td>
                                    {$customerCard.iconHtml}
                                </td>
                                <td>{$customerCard.displayName|escape:'html':'UTF-8'}</td>
                                <td>{$customerCard.expiration|date_format:'%m/%y'|escape:'html':'UTF-8'}</td>
                                <td>{$customerCard.dateAdd|escape:'html':'UTF-8'}</td>
                                <td class="action">
                                <a href="#" class="btn btn-danger btn-sm delete-card-btn" 
                                    data-customer-card-id="{$customerCard.id|escape:'html':'UTF-8'}"
                                    data-toggle="modal" 
                                    data-target="#deleteCardModal"
                                    aria-label="{l s='Delete card ending in %s' mod='monei' sprintf=[$customerCard.lastFourWithMask|escape:'html':'UTF-8']}"
                                    title="{l s='Delete card' mod='monei'}">
                                        {if !$isWarehouseInstalled}
                                            <i class="material-icons" aria-hidden="true">delete</i>
                                        {else}
                                            <i class="fa fa-trash" aria-hidden="true"></i>
                                        {/if}
                                        <span class="sr-only">{l s='Delete card' mod='monei'}</span>
                                    </a>
                                </td>
                            </tr>
                        {/foreach}
                    {/if}
                </tbody>
            </table>
        </div>
    </div>
    
    {* Bootstrap Modal for Delete Confirmation *}
    <div class="modal fade" id="deleteCardModal" tabindex="-1" role="dialog" aria-labelledby="deleteCardModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title h5" id="deleteCardModalLabel">{l s='Remove Card' mod='monei'}</h4>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p>{l s='Are you sure you want to remove this card?' mod='monei'}</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default btn-secondary" data-dismiss="modal">{l s='Cancel' mod='monei'}</button>
                    <button type="button" class="btn btn-default btn-danger" id="confirmDeleteCard">
                        {l s='Yes, remove it' mod='monei'}
                    </button>
                </div>
            </div>
        </div>
    </div>
{/block}