<form action="{$link_create_payment}" method="post" id="monei-payment-form">
    {if $payment_method == 'card'}
        <div class="form-group">
            <label class="form-check-label">
                <input type="checkbox" name="tokenize_card" value="1" id="tokenize_card">
                {l s='Save Card details for future payments' mod='monei'}
            </label>
        </div>
    {/if}
</form>