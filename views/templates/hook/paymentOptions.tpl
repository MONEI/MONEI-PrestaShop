<form action="{$link_create_payment|escape:'html':'UTF-8'}" method="post" id="monei-payment-form">
    <div class="form-control">
        <input class="input-form" type="checkbox" name="tokenize_card" value="1" id="tokenize_card">
        <label class="form-control-label" for="tokenize_card">{l s='Save Card details for future payments' mod='monei'}</label>
    </div>
</form>