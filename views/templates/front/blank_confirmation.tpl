

{extends file='page.tpl'}

{block name='page_header_container'}
    <script>
        const monei_cart_id = '{$monei_cart_id|escape:'htmlall':'UTF-8'}';
        const monei_order_id = '{$monei_order_id|escape:'htmlall':'UTF-8'}';
        const monei_id = '{$monei_id|escape:'htmlall':'UTF-8'}';
    </script>
    <header class="page-header">
        <h1 class="h1 page-title">
            {if $monei_success === true}
                <span>{l s='Order' mod='monei'} #<span
                            id="order_id_span">{$order_id|escape:'html':'UTF-8'}</span></span>
            {else}
                <span>{l s='Payment error' mod='monei'}</span>
            {/if}
        </h1>
    </header>
{/block}

{block name='page_title'}

{/block}

{block name='page_content'}
    {if $monei_success === true}
        <div class="custom__modal">
            <div class="custom__modal__content">
                <div class="custom__modal__spinner"></div>
                <p class="p__spiner">
                    {l s='We are validating your payment, please wait and don\'t close this window' mod='monei'}
                </p>
                <p class="p_timeleft">
                    <strong>{l s='Maximum time left' mod='monei'}:</strong> <span id="countdown">...</span>
                </p>
                {* <img src="{$module_dir|escape:'htmlall':'UTF-8'}/views/img/credit_payment_icon.png"> *}
            </div>
        </div>
    {else}
        {block name="errors"}
            {include file='module:monei/views/templates/front/error.tpl'}
        {/block}
    {/if}
{/block}

{block name="page_footer"}
    <a href="{$urls.pages.my_account}" class="account-link">
        <i class="material-icons">&#xE5CB;</i>
        <span>{l s='Back to your account' d='Shop.Theme.Customeraccount'}</span>
    </a>
    <a href="{$urls.pages.index}" class="account-link">
        <i class="material-icons">&#xE88A;</i>
        <span>{l s='Home' d='Shop.Theme.Global'}</span>
    </a>
{/block}