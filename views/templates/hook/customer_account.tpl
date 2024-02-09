

{if $is_warehouse == 0}
    <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" title="{l s='My Credit Cards' mod='monei'}"
       href="{url entity='module' name='monei' controller='cards'}">
        <span class="link-item">
            <i class="material-icons">credit_card</i>
            {l s='My Credit Cards' mod='monei'}
        </span>
    </a>
{else}
    {* Specific for warehouse theme *}
    <a class="col-lg-4 col-md-6 col-sm-6 col-xs-12" title="{l s='My Credit Cards' mod='monei'}"
       href="{url entity='module' name='monei' controller='cards'}">
        <span class="link-item">
            <span class="fa fa-credit-card" aria-hidden="true"></span>
            {l s='My Credit Cards' mod='monei'}
        </span>
    </a>
{/if}