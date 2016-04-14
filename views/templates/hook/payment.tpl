<div class="row">
    <div class="col-xs-12">
        <p class="payment_module">
            <a class="bankwire moneiPaymentModule" href="{{$moneiPaymentURL}}" title="Pay with Monei.">
                Pay with Monei <span></span>
            </a>
        </p>
    </div>
    {if $hasError}
        <div class="module_error alert alert-danger col-xs-12">
            <span>An error occured:<br/></span>
            <div>
                <p>
                    {{$errorMessage}}
                </p>
            </div>
        </div>
    {/if}
</div>

