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
            <button type="button" class="close" data-dismiss="alert">×</button>
            {{$errorMessage}}
        </div>
    {/if}
</div>

