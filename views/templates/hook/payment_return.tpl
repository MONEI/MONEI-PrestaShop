{if $status == 'ok'}
    <a href="{$smarty.const.__PS_BASE_URI__} " class="btn btn-primary">{l s='Continue Shopping' mod='moneipayments'}</a>
{else}
    <div class="alert alert-danger">
        {l s='We noticed a problem with your order. If you think this is an error, feel free to contact our' mod='moneipayments'}
        <a href="{$link->getPageLink('contact', true)|escape:'html'}">{l s='expert customer support team' mod='moneipayments'}</a>.
    </div>
{/if}
