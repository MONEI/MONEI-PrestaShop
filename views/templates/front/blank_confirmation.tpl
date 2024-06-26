{extends file='page.tpl'}

{block name='page_title'}
    {if $monei_success === true}
        {l s='Order' mod='monei'} #<span id="order_id_span">{$monei_order_id|escape:'html':'UTF-8'}</span>
    {else}
        {l s='Payment error' mod='monei'}
    {/if}
{/block}

{block name='page_content'}
    {if $monei_success === true}
        <script>
            const monei_cart_id = '{$monei_cart_id|escape:'htmlall':'UTF-8'}';
            const monei_order_id = '{$monei_order_id|escape:'htmlall':'UTF-8'}';
            const monei_id = '{$monei_id|escape:'htmlall':'UTF-8'}';
        </script>

        <div class="custom__modal">
            <div class="custom__modal__content">
                <div class="custom__modal__spinner"></div>
                <p class="p__spiner">
                    {l s='We are validating your payment, please wait and don\'t close this window' mod='monei'}
                </p>
                <p class="p_timeleft">
                    <strong>{l s='Maximum time left' mod='monei'}:</strong> <span id="countdown">...</span>
                </p>
            </div>
        </div>
    {else}
        {block name="errors"}
            {include file='module:monei/views/templates/front/error.tpl'}
        {/block}
    {/if}
{/block}

{block name='page_footer'}
    {block name='my_account_links'}
        {include file='customer/_partials/my-account-links.tpl'}
    {/block}
{/block}