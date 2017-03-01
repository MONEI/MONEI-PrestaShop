{capture name=path}
    <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html':'UTF-8'}"
       title="{l s='Go back to the Checkout' mod='moneipaymentplatform'}">{l s='Checkout' mod='moneipaymentplatform'}</a>
    <span class="navigation-pipe">{$navigationPipe}</span>{l s='Monei Payment Platform' mod='moneipaymentplatform'}
{/capture}


<h2>{l s='Order summary' mod='moneipaymentplatform'}</h2>

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}

{if $nbProducts <= 0}
    <p class="warning">{l s='Your shopping cart is empty.' mod='moneipaymentplatform'}</p>
    <a href="{$base_dir|escape:'html'}"
       class="button_large">Continue</a>
{else}
    <div class="row">
        <div class="col-xs-12">
            <h3>{l s='Monei Payment Method' mod='moneipaymentplatform'}</h3>

            <p style="margin-top:20px;">
                - {l s='The total amount of your order is' mod='moneipaymentplatform'}
                <span id="amount" class="price">{displayPrice price=$total} </span>
            </p>

            <div class="payment_module">

                {if $checkoutID != null && !$hasError}
                    <script src="https://{{$apiHost}}/v1/paymentWidgets.js?checkoutId={{$checkoutID}}"></script>
                    <form action="{{{$base_dir|escape:'html'}}}?fc=module&module=moneipaymentplatform&controller=validation"
                          class="paymentWidgets">{{$allowedPaymentMethods}}</form>
                {/if}

                {if $hasError}
                    <div class="module_error alert alert-danger">
                        <button type="button" class="close" data-dismiss="alert">×</button>
                        {{$errorMessage}}
                    </div>
                {/if}

                {if $isRedirectedWithError }
                    <div class="module_error alert alert-danger">
                        <button type="button" class="close" data-dismiss="alert">×</button>
                        {{$redirectedErrorMessage}}
                    </div>
                {/if}

            </div>
        </div>
    </div>

{/if}
