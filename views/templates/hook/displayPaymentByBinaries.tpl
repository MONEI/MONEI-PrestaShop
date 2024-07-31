<script>
  window.moneiPaymentId = '{$moneiPaymentId|escape:'htmlall':'UTF-8'}';

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

      const saveCard = document.getElementById('monei-tokenize-card');
      if (saveCard && saveCard.checked) {
        params.generatePaymentToken = true;
      }

      Swal.fire({
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        background: 'none',
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

              if (result.status === 'SUCCEEDED') {
                Swal.fire({
                  title: result.status,
                  text: result.statusMessage,
                  icon,
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  showConfirmButton: false,
                });

                window.location.assign(result.nextAction.redirectUrl);
              } else {
                Swal.fire({
                  title: result.status,
                  text: result.statusMessage,
                  icon,
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  confirmButtonText: window.moneiMsgRetry,
                  willClose: () => {
                    window.location.reload();
                  },
                });
              }
            }
          } catch (error) {
            Swal.fire({
              title: error.status + '(' + error.statusCode + ')',
              text: error.message,
              icon: 'error',
              allowOutsideClick: false,
              allowEscapeKey: false,
              confirmButtonText: window.moneiMsgRetry,
              willClose: () => {
                window.location.reload();
              },
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
          onBeforeOpen: () => {
            const conditionsToApprove = document.getElementById('conditions-to-approve');
            if (conditionsToApprove) {
              const requiredCheckboxes = conditionsToApprove.querySelectorAll('input[type="checkbox"][required]');
              for (let i = 0; i < requiredCheckboxes.length; i++) {
                if (!requiredCheckboxes[i].checked) {
                  return false;
                }
              }
              return true;
            }
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

          var moneiCardHolderName = document.getElementById('monei-card-holder-name');
          var moneiCardErrors = document.getElementById('monei-card-errors');

          var validateMoneiCardHolderName = (moneiCardHolderName) => {
            const patternCardHolderName = /^[A-Za-zÀ-ú- ]{5,50}$/;

            if (patternCardHolderName.test(moneiCardHolderName) === false) {
              moneiCardErrors.innerHTML = '<div class="alert alert-warning">' + window.moneiCardHolderNameNotValid + '</div>';
              moneiCardButton.classList.add('disabled');
              moneiCardButton.disabled = true;

              return false;
            } else {
              moneiCardErrors.innerHTML = '';
              moneiCardButton.classList.remove('disabled');
              moneiCardButton.disabled = false;

              return true;
            }
          }

          var moneiCardInput = monei.CardInput({
            paymentId: window.moneiPaymentId,
            onEnter: () => {
              moneiPaymentFormButton.click();
            },
          });
          moneiCardInput.render(moneiCardRenderContainer);

          moneiCardHolderName.addEventListener('input', (event) => {
            validateMoneiCardHolderName(event.currentTarget.value);
          });

          moneiPaymentForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            e.stopPropagation();

            const isValid = moneiPaymentForm.checkValidity();
            if (!isValid) {
              return;
            }

            if (validateMoneiCardHolderName(moneiCardHolderName.value) === false) {
              return;
            }

            moneiCardButton.disabled = true;

            try {
              const {token, error} = await monei.createToken(moneiCardInput);
              if (!token) {
                moneiCardErrors.innerHTML = '<div class="alert alert-warning">' + error + '</div>';
                moneiCardButton.classList.remove('disabled');
                moneiCardButton.disabled = false;

                return;
              }

              moneiCardErrors.innerHTML = '';

              await moneiTokenHandler(token, moneiCardHolderName.value, moneiCardButton);
            } catch (error) {
              moneiCardErrors.innerHTML = '<div class="alert alert-warning">' + error.message + '</div>';
              moneiCardButton.classList.remove('disabled');
              moneiCardButton.disabled = false;

              console.log('createToken - Card Input - error', error);
            }
          });
        }
      </script>
    {/literal}
  {/if}
{/foreach}