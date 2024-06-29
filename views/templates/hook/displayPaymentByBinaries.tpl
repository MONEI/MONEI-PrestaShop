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
  {elseif $paymentOptionName eq 'monei-card'}
    {literal}
    <script>
      let moneiCardInput = monei.CardInput({
        paymentId: '5163d73c8cd031c77f5b6a82857c226856b2f9b3',
      });
      moneiCardInput.render('#monei-card_container');

      let moneiCardContainer = document.getElementById('monei-card-buttons-container');

      let paymentButton = document.createElement('button');
      paymentButton.classList.add('monei-button');
      paymentButton.innerText = 'Checkout';
      paymentButton.addEventListener('click', function(event) {
        event.preventDefault();
        paymentButton.disabled = true;
        monei
          .createToken(moneiCardInput)
          .then(function(result) {
            console.log(result);
            if (result.error) {
              // Inform the user if there was an error.
              // container.classList.add('is-invalid');
              // errorText.innerText = result.error;
            } else {
              // Send the token to MONEI.
              moneiTokenHandler(result.token);
            }
            paymentButton.disabled = false;
          })
          .catch(function(error) {
            paymentButton.disabled = false;
            console.log(error);
          });
      });

      moneiCardContainer.appendChild(paymentButton);

      function moneiTokenHandler(token) {
        return monei
          .confirmPayment({
            paymentId: "5163d73c8cd031c77f5b6a82857c226856b2f9b3",
            paymentToken: token,
            paymentMethod: {card: {cardholderName: 'JOHN DOE'}}
          })
          .then(function(response) {
            if (typeof response.nextAction !== typeof undefined) {
              const redirectURL = response.nextAction.redirectUrl;
              if (response.status === 'FAILED') {
                redirectURL += '&message=' + $response.statusMessage;
              }

              window.location.href = redirectURL;
            }
          })
          .catch(function(error) {
            console.log(error);
          });
      }
    </script>
    {/literal}
  {/if}
{/foreach}