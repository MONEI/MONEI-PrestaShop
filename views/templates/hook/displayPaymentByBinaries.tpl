{foreach from=$paymentMethodsToDisplay item="paymentOptionName"}
  <section class="js-payment-binary js-payment-monei js-payment-{$paymentOptionName|escape:'htmlall':'UTF-8'} mt-1 disabled">
    <div id="{$paymentOptionName|escape:'htmlall':'UTF-8'}-buttons-container">
      <div class="monei-button" data-funding-source="{$paymentOptionName|escape:'htmlall':'UTF-8'}">
        <form action="https://secure.monei.com/payments/{$paymentId|escape:'htmlall':'UTF-8'}/confirm" method="post" id="payment-form">
          <div id="{$paymentOptionName|escape:'htmlall':'UTF-8'}_container"></div>
        </form>
      </div>
    </div>
  </section>

  {if $paymentOptionName eq 'monei-bizum'}
    <script>
      monei.Bizum({
        paymentId: '{$paymentId|escape:'htmlall':'UTF-8'}',
        onSubmit(result) {
          moneiTokenHandler(result.token);
        },
        onError(error) {
          console.log(error);
        }
      })
      .render('#{$paymentOptionName|escape:'htmlall':'UTF-8'}_container');
    </script>
  {/if}
{/foreach}