<div id="payment-form-monei">
    <div class="form-group">
        <input type="text" class="form-control" id="monei-card-holder-name" placeholder="{l s='Card holder name' mod='monei'}" required>
    </div>
    <div class="form-group">
        <div id="monei-card_container" class="form-control"></div>
    </div>
    <div id="monei-card-errors" class="form-group"></div>
    {if $isCustomerLogged && $tokenize}
        <div class="form-group">
            <label class="form-check-label">
                <input type="checkbox" name="monei-tokenize-card" id="monei-tokenize-card">
                {l s='Save Card details for future payments' mod='monei'}
            </label>
        </div>
    {/if}
</div>