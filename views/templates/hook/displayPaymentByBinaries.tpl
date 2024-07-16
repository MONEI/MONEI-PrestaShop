<script>
  window.moneiPaymentId = '{$moneiPaymentId|escape:'htmlall':'UTF-8'}';
  window.moneiCardHolderName = '{$moneiCardHolderName|escape:'htmlall':'UTF-8'}';

  {literal}
    var moneiTokenHandler = async (paymentToken, cardholderName, paymentButton) => {
      const params = {
        paymentId: window.moneiPaymentId,
        paymentToken
      };

      if (cardholderName) {
        params.paymentMethod = {
          card: {
            cardholderName
          }
        };
      }

      Swal.fire({
        title: window.moneiProcessing,
        allowOutsideClick: false,
        allowEscapeKey: false,
        didOpen: async () => {
          Swal.showLoading();

          try {
            const result = await monei.confirmPayment(params);

            console.log('moneiTokenHandler - confirmPayment', params, result);

            Swal.hideLoading();

            if (result.nextAction && result.nextAction.mustRedirect) {
              window.location.assign(result.nextAction.redirectUrl);
            } else {
              const icon = result.status === 'SUCCEEDED' ? 'success' : 'error';

              Swal.fire({
                title: result.status,
                text: result.statusMessage,
                icon,
                allowOutsideClick: result.status === 'SUCCEEDED' ? false : true,
                allowEscapeKey: result.status === 'SUCCEEDED' ? false : true,
                showConfirmButton: false,
              });

              if (result.status === 'SUCCEEDED') {
                window.location.assign(result.nextAction.redirectUrl);
              } else {
                window.location.reload();
              }
            }
          } catch (error) {
            paymentButton.disabled = false;
            Swal.fire({
              title: error.status + '(' + error.statusCode + ')',
              text: error.message,
              icon: 'error',
            });

            console.log('moneiTokenHandler - error', params, error);
          }
        },
      });
    };
  {/literal}
</script>

{foreach from=$paymentMethodsToDisplay item="paymentOptionName"}
  <section
    class="js-payment-binary js-payment-monei js-payment-{$paymentOptionName|escape:'htmlall':'UTF-8'} mt-1 disabled">
    <div id="{$paymentOptionName|escape:'htmlall':'UTF-8'}-buttons-container">
      <form action="https://secure.monei.com/payments/{$moneiPaymentId|escape:'htmlall':'UTF-8'}/confirm" method="post">
        <div class="{$paymentOptionName|escape:'htmlall':'UTF-8'}_render"></div>

        {if $paymentOptionName eq 'monei-card'}
          <button class="btn btn-primary btn-block" type="submit">
            <i class="material-icons">payment</i>
            {l s='Pay' mod='monei'}&nbsp;&nbsp;{$moneiAmount|escape:'htmlall':'UTF-8'}
          </button>
        {/if}
      </form>
    </div>
  </section>

  {if $paymentOptionName eq 'monei-bizum'}
    <script>
      function initMoneiBizum() {
        var moneiBizumButtonsContainer = document.getElementById('monei-bizum-buttons-container');
        if (moneiBizumButtonsContainer === null) {
          return;
        }

        var moneiBizumRenderContainer = moneiBizumButtonsContainer.querySelector('.monei-bizum_render');
        if (moneiBizumRenderContainer === null) {
          return;
        }

        monei.Bizum({
          paymentId: window.moneiPaymentId,
          style: {
            height: 42
          },
          onSubmit(result) {
            if (result.token) {
              moneiTokenHandler(result.token);
            }

            console.log('onSubmit - Bizum', result);
          },
          onError(error) {
            Swal.fire({
              title: error.status + '(' + error.statusCode + ')',
              text: error.message,
              icon: 'error',
            });

            console.log('onError - Bizum', error);
          }
        })
        .render(moneiBizumRenderContainer);
      }
    </script>
  {elseif $paymentOptionName eq 'monei-card'}
    {literal}
      <script>
        function initMoneiCard() {
          var moneiCardButtonsContainer = document.getElementById('monei-card-buttons-container');
          if (moneiCardButtonsContainer === null) {
            return;
          }

          var moneiPaymentForm = moneiCardButtonsContainer.querySelector('form');
          if (moneiPaymentForm === null) {
            return;
          }

          var moneiCardButton = moneiPaymentForm.querySelector('button[type="submit"]');
          if (moneiCardButton === null) {
            return;
          }

          var moneiCardRenderContainer = document.getElementById('monei-card_container');
          if (moneiCardRenderContainer === null) {
            return;
          }

          var moneiCardInput = monei.CardInput({
            paymentId: window.moneiPaymentId,
            onEnter: () => {
              moneiPaymentFormButton.click();
            },
          });
          moneiCardInput.render(moneiCardRenderContainer);

          moneiPaymentForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const isValid = moneiPaymentForm.checkValidity();
            if (!isValid) {
              return;
            }
            moneiCardButton.disabled = true;

            try {
              const {token} = await monei.createToken(moneiCardInput);
              if (!token) {
                moneiCardButton.disabled = false;

                return;
              }

              await moneiTokenHandler(token, window.moneiCardHolderName, moneiCardButton);
            } catch (error) {
              moneiCardButton.disabled = false;
              Swal.fire({
                title: error.status + '(' + error.statusCode + ')',
                text: error.message,
                icon: 'error',
              });

              console.log('createToken - Card Input - error', error);
            }
          });
        }
      </script>
    {/literal}
  {/if}
{/foreach}