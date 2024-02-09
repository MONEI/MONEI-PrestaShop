

{if $monei_success === true}
    <div class="box">
        {hook h='displayOrderConfirmation' order=$order objOrder=$order}
        <p>
            <strong>{l s='Congratulations, transaction completed successfully.' mod='monei'}</strong>
        </p>
        <p>
            {l s='If you have questions, comments or concerns, please contact our' mod='monei'} <a
                    href="{url entity="contact"}">{l s='expert customer support team.' mod='monei'}</a>
        </p>
        <p>
            &nbsp;
        </p>
        <p class="bold">
            {l s='Check your order details' mod='monei'} <a
                    href="{url entity="order-detail" params=['id_order' => $id_order]}">{l s='HERE.' mod='monei'}</a>
        </p>
    </div>
{else}
    <p>
        {block name='errors'}
            {include file="module:monei/views/templates/front/error.tpl"}
        {/block}
    </p>
{/if}