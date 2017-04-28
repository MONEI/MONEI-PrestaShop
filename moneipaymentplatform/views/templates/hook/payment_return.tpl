{if $status == 'ok'}
    <div class="alert alert-success">
        <button type="button" class="close" data-dismiss="alert">×</button>
        {l s='Order Completed!' mod='moneipaymentplatform'}
    </div>

    <a href="{$base_dir|escape:'html'}"
       class="button_large">{l s='Continue Shopping' mod='moneipaymentplatform'}</a>
{else}
    <p class="warning">
        {l s='We noticed a problem with your order. If you think this is an error, feel free to contact our' mod='moneipaymentplatform'}
        <a href="{$link->getPageLink('contact', true)|escape:'html'}">{l s='expert customer support team' mod='moneipaymentplatform'}</a>.
    </p>
{/if}
