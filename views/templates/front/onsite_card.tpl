<div class="form-group">
    <input type="text" class="form-control" id="monei-card-holder-name" placeholder="{l s='Card holder name' mod='monei'}" required>
</div>
<div class="form-group">
    <div id="monei-card_container" class="form-control"></div>
</div>
<div id="monei-card-errors" class="form-group"></div>
{if $isCustomerLogged}
    <div class="form-group">
        <input class="input-form" type="checkbox" name="monei-tokenize-card" id="monei-tokenize-card">
        <label class="form-control-label monei-tokenize-card" for="monei-tokenize-card">{l s='Save Card details for future payments' mod='monei'}</label>
    </div>
{/if}