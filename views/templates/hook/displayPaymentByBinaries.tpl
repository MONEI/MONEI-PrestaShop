<script>
  var moneiPaymentId = '{$moneiPaymentId|escape:'htmlall':'UTF-8'}';
  var moneiCustomerData = {$customerData|json_encode nofilter};
  var moneiBillingData = {$billingData|json_encode nofilter};
  var moneiShippingData = {$shippingData|json_encode nofilter};

  {literal}
    var moneiTokenHandler = async (paymentToken, cardholderName) => {
      // support module onepagecheckoutps - v4 - PresTeamShop
      const customerEmail = document.getElementById('customer_email');
      if (customerEmail) {
        moneiCustomerData.email = customerEmail.value;
      }

      const params = {
        paymentId: moneiPaymentId,
        paymentToken,
        customer: moneiCustomerData,
        billingDetails: moneiBillingData,
        shippingDetails: moneiShippingData,
      };

      if (cardholderName) {
        params.paymentMethod = {
          card: {
            cardholderName,
            cardholderEmail: moneiCustomerData.email,
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

            if (result.nextAction && result.nextAction.mustRedirect) {
              location.assign(result.nextAction.redirectUrl);
            } else {
              const icon = result.status === 'SUCCEEDED' ? 'success' : 'error';

              if (result.status === 'SUCCEEDED') {
                location.assign(result.nextAction.redirectUrl);
              } else {
                Swal.fire({
                  title: result.status,
                  text: result.statusMessage,
                  icon,
                  allowOutsideClick: false,
                  allowEscapeKey: false,
                  confirmButtonText: moneiMsgRetry,
                  willClose: () => {
                    location.reload();
                  },
                });
              }
            }
          } catch (error) {
            Swal.fire({
              title: `${error.status} (${error.statusCode})`,
              text: error.message,
              icon: 'error',
              allowOutsideClick: false,
              allowEscapeKey: false,
              confirmButtonText: moneiMsgRetry,
              willClose: () => {
                location.reload();
              },
            });
            console.log('moneiTokenHandler - error', params, error);
          }
        },
      });
    };

    var moneiValidConditions = () => {
      const conditionsToApprove = document.getElementById('conditions-to-approve');
      if (conditionsToApprove) {
        const requiredCheckboxes = conditionsToApprove.querySelectorAll('input[type="checkbox"][required]');
        return Array.from(requiredCheckboxes).every(checkbox => checkbox.checked);
      }
      return true;
    };

    var moneiAddChangeEventToCheckboxes = (moneiButton) => {
      const conditionsToApprove = document.getElementById('conditions-to-approve');
      if (conditionsToApprove) {
        const requiredCheckboxes = conditionsToApprove.querySelectorAll('input[type="checkbox"][required]');
        requiredCheckboxes.forEach(checkbox => {
          checkbox.addEventListener('change', () => {
            if (moneiValidConditions()) {
              moneiEnableButton(moneiButton);
            } else {
              moneiDisableButton(moneiButton);
            }
          });
        });
      }
    };

    var moneiEnableButton = (moneiButton) => {
      if (moneiButton) {
        // In some PS versions, the handler fails to disable the button because of the timing.
        setTimeout(() => {
          moneiButton.classList.remove('disabled');
          moneiButton.disabled = false;
        }, 0);
      }
    };

    var moneiDisableButton = (moneiButton) => {
      if (moneiButton) {
        moneiButton.classList.add('disabled');
        moneiButton.disabled = true;
      }
    };
  {/literal}
</script>

{foreach from=$paymentMethodsToDisplay item="paymentOptionName"}
  <section
    class="js-payment-binary js-payment-monei js-payment-{$paymentOptionName|escape:'htmlall':'UTF-8'} mt-1 disabled">
    <div id="{$paymentOptionName|escape:'htmlall':'UTF-8'}-buttons-container">
      <form action="https://secure.monei.com/payments/{$moneiPaymentId|escape:'htmlall':'UTF-8'}/confirm" method="post">
        <input type="hidden" name="option" value="binary">
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
    {literal}
      <script>
        function initMoneiBizum() {
          const moneiBizumButtonsContainer = document.getElementById('monei-bizum-buttons-container');
          if (!moneiBizumButtonsContainer) return;

          const moneiBizumRenderContainer = moneiBizumButtonsContainer.querySelector('.monei-bizum_render');
          if (!moneiBizumRenderContainer) return;

          monei.Bizum({
            paymentId: moneiPaymentId,
            style: moneiBizumStyle || {},
            onBeforeOpen: moneiValidConditions,
            onSubmit(result) {
              if (result.token) moneiTokenHandler(result.token);
            },
            onError(error) {
              Swal.fire({
                title: `${error.status} (${error.statusCode})`,
                text: error.message,
                icon: 'error',
              });
              console.log('onError - Bizum', error);
            }
          }).render(moneiBizumRenderContainer);
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-card'}
    {literal}
      <script>
        function initMoneiCard() {
          const sectionMoneiCard = document.querySelector('.js-payment-monei-card');
          if (!sectionMoneiCard) return;

          const moneiCardButtonsContainer = document.getElementById('monei-card-buttons-container');
          if (!moneiCardButtonsContainer) return;

          const moneiPaymentForm = moneiCardButtonsContainer.querySelector('form');
          if (!moneiPaymentForm) return;

          const moneiCardButton = moneiPaymentForm.querySelector('button[type="submit"]');
          if (!moneiCardButton) return;

          const moneiCardRenderContainer = document.getElementById('monei-card_container');
          if (!moneiCardRenderContainer) return;

          const moneiCardHolderName = document.getElementById('monei-card-holder-name');
          const moneiCardErrors = document.getElementById('monei-card-errors');

          // support for PrestaShop versions lower than 1.7.8.X
          moneiAddChangeEventToCheckboxes(moneiCardButton);
          if (moneiValidConditions()) {
            moneiEnableButton(moneiCardButton);
          } else {
            moneiDisableButton(moneiCardButton);
          }

          const validateMoneiCardHolderName = (name) => {
            const patternCardHolderName = /^[A-Za-zÀ-ú- ]{5,50}$/;
            const isValid = patternCardHolderName.test(name);
            moneiCardErrors.innerHTML = isValid ? '' : `<div class="alert alert-warning">${moneiCardHolderNameNotValid}</div>`;
            return isValid;
          };

          const moneiCardInput = monei.CardInput({
            paymentId: moneiPaymentId,
            onChange: () => { moneiCardErrors.innerHTML = ''; },
            onEnter: () => { moneiCardButton.click(); },
            language: prestashop.language.iso_code,
            style: moneiCardInputStyle || {},
          });
          moneiCardInput.render(moneiCardRenderContainer);

          moneiCardHolderName.addEventListener('blur', (event) => {
            validateMoneiCardHolderName(event.currentTarget.value);
          });

          moneiPaymentForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            e.stopPropagation();

            moneiDisableButton(moneiCardButton);

            if (!moneiPaymentForm.checkValidity() || !validateMoneiCardHolderName(moneiCardHolderName.value)) {
              moneiEnableButton(moneiCardButton);
              return;
            }

            try {
              const { token, error } = await monei.createToken(moneiCardInput);
              if (!token) {
                moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error}</div>`;
                moneiEnableButton(moneiCardButton);
                return;
              }

              moneiCardErrors.innerHTML = '';
              await moneiTokenHandler(token, moneiCardHolderName.value);
            } catch (error) {
              moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error.message}</div>`;
              moneiEnableButton(moneiCardButton);
              console.log('createToken - Card Input - error', error);
            }
          });
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-googlePay'}
    {literal}
      <script>
        function initMoneiGooglePay() {
          const moneiPaymentRequestButtonsContainer = document.getElementById('monei-googlePay-buttons-container');
          if (!moneiPaymentRequestButtonsContainer) return;

          const moneiPaymentRequestRenderContainer = moneiPaymentRequestButtonsContainer.querySelector(
            '.monei-googlePay_render');
          if (!moneiPaymentRequestRenderContainer) return;

          monei.PaymentRequest({
            paymentId: moneiPaymentId,
            style: moneiPaymentRequestStyle || {},
            onBeforeOpen: moneiValidConditions,
            onSubmit(result) {
              if (result.token) moneiTokenHandler(result.token);
            },
            onError(error) {
              Swal.fire({
                title: `${error.status} (${error.statusCode})`,
                text: error.message,
                icon: 'error',
              });
              console.log('onError - Google Pay', error);
            }
          }).render(moneiPaymentRequestRenderContainer);
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-applePay'}
    {literal}
      <script>
        function initMoneiApplePay() {
          const moneiPaymentRequestButtonsContainer = document.getElementById('monei-applePay-buttons-container');
          if (!moneiPaymentRequestButtonsContainer) return;

          const moneiPaymentRequestRenderContainer = moneiPaymentRequestButtonsContainer.querySelector(
            '.monei-applePay_render');
          if (!moneiPaymentRequestRenderContainer) return;

          monei.PaymentRequest({
            paymentId: moneiPaymentId,
            style: moneiPaymentRequestStyle || {},
            onBeforeOpen: moneiValidConditions,
            onSubmit(result) {
              if (result.token) moneiTokenHandler(result.token);
            },
            onError(error) {
              Swal.fire({
                title: `${error.status} (${error.statusCode})`,
                text: error.message,
                icon: 'error',
              });
              console.log('onError - Apple Pay', error);
            }
          }).render(moneiPaymentRequestRenderContainer);
        }
      </script>
    {/literal}
  {/if}
{/foreach}