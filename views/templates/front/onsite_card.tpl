<div id="payment-form-monei">
    <div class="form-group">
        <input type="text" class="form-control" id="monei-card-holder-name" placeholder="{l s='Card holder name' mod='monei'}" required>
    </div>
    {if $moneiCardLayout == 'split'}
        {* Split layout: one CardGroup carries the payment details and these three
           containers only present it. payment.js mounts a part into each. *}
        <div class="form-group">
            <div id="monei-card-number" class="form-control"></div>
        </div>
        <div class="row">
            <div class="col-6 form-group">
                <div id="monei-card-expiry" class="form-control"></div>
            </div>
            <div class="col-6 form-group">
                <div id="monei-card-cvc" class="form-control"></div>
            </div>
        </div>
    {else}
        <div class="form-group">
            <div id="monei-card_container" class="form-control"></div>
        </div>
    {/if}
    <div id="monei-card-errors" class="form-group"></div>
    {if $isCustomerLogged && $tokenize}
        <div class="form-group" style="margin-left: 0; padding-left: 0;">
            <label class="form-check-label" style="margin-left: 0; padding-left: 0;">
                <input type="checkbox" name="monei-tokenize-card" id="monei-tokenize-card"> {l s='Save Card details for future payments' mod='monei'}
            </label>
        </div>
    {/if}
</div>