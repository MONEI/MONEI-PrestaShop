<div class="row">
    <div class="col-xs-12">
        <p class="payment_module">
            <a class="bankwire moneiPaymentModule" href="{{$moneiPaymentURL}}" title="Pay with Monei.">
                {l s='Pay with MONEI' mod='moneipaymentplatform'}<span></span>
            </a>
        </p>
    </div>
    {if $hasError}
        <div class="module_error alert alert-danger col-xs-12">
            <span>{l s='An error occured:' mod='moneipaymentplatform'}<br/></span>
            <div>
                <p>
                    {{$errorMessage}}
                </p>
            </div>
        </div>
    {/if}
</div>

