{capture name=path}
    <a href="{$link->getPageLink('order', true, NULL, "step=3")|escape:'html':'UTF-8'}"
       title="{l s='Go back to the Checkout' mod='monei'}">{l s='Checkout' mod='monei'}</a>
    <span class="navigation-pipe">{$navigationPipe}</span>{l s='MONEI Payment Gateway' mod='monei'}
{/capture}


<h2>{l s='Order summary' mod='monei'}</h2>

{assign var='current_step' value='payment'}
{include file="$tpl_dir./order-steps.tpl"}

{if $nbProducts <= 0}
    <p class="warning">{l s='Your shopping cart is empty.' mod='monei'}</p>
    <a href="{$base_dir|escape:'html'}"
       class="button_large">Continue</a>
{else}
    <div class="row">
        <div class="col-xs-12">
            <h3>{l s='MONEI Payment Gateway' mod='monei'}</h3>

            <p style="margin-top:20px;">
                - {l s='The total amount of your order is' mod='monei'}
                <span id="amount" class="price">{displayPrice price=$total} </span>
            </p>

            <div class="payment_module">

                {if $checkoutID != null && !$hasError}
                    <script>
                        var wpwlOptions = {
                            style:"plane",
                            locale: "{$currentLanguage}"
                        }
                    </script>
                    <script src="https://test.monei-api.net/v1/paymentWidgets.js?checkoutId={{$checkoutID}}"></script>
                    <form action="{{{$base_dir|escape:'html'}}}?fc=module&module=monei&controller=validation"
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
