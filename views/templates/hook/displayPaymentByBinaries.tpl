<script>
  window.moneiPaymentId = '{$moneiPaymentId|escape:'htmlall':'UTF-8'}';
  window.moneiCustomerData = {$customerData|json_encode nofilter};
  window.moneiBillingData = {$billingData|json_encode nofilter};
  window.moneiShippingData = {$shippingData|json_encode nofilter};

  {literal}
    var moneiTokenHandler = async (paymentToken, cardholderName, paymentButton) => {
      // support module onepagecheckoutps - v4 - PresTeamShop
      const customerEmail = document.getElementById('customer_email');
      if (customerEmail) {
        window.moneiCustomerData.email = customerEmail.value;
      }

      const params = {
        paymentId: window.moneiPaymentId,
        paymentToken,
        customer: window.moneiCustomerData,
        billingDetails: window.moneiBillingData,
        shippingDetails: window.moneiShippingData,
      };

      if (cardholderName) {
        params.paymentMethod = {
          card: {
            cardholderName,
            cardholderEmail: window.moneiCustomerData.email,
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
              window.location.assign(result.nextAction.redirectUrl);
            } else {
              const icon = result.status === 'SUCCEEDED' ? 'success' : 'error';

              if (result.status === 'SUCCEEDED') {
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
              title: `${error.status} (${error.statusCode})`,
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

    var moneiValidConditions = () => {
      const conditionsToApprove = document.getElementById('conditions-to-approve');
      if (conditionsToApprove) {
        const requiredCheckboxes = conditionsToApprove.querySelectorAll('input[type="checkbox"][required]');
        return Array.from(requiredCheckboxes).every(checkbox => checkbox.checked);
      }
      return true;
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
    {literal}
      <script>
        function initMoneiBizum() {
          const moneiBizumButtonsContainer = document.getElementById('monei-bizum-buttons-container');
          if (!moneiBizumButtonsContainer) return;

          const moneiBizumRenderContainer = moneiBizumButtonsContainer.querySelector('.monei-bizum_render');
          if (!moneiBizumRenderContainer) return;

          monei.Bizum({
            paymentId: window.moneiPaymentId,
            style: window.moneiBizumStyle || {},
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

          const validateMoneiCardHolderName = (name) => {
            const patternCardHolderName = /^[A-Za-zÀ-ú- ]{5,50}$/;
            const isValid = patternCardHolderName.test(name);
            moneiCardErrors.innerHTML = isValid ? '' : `<div class="alert alert-warning">${window.moneiCardHolderNameNotValid}</div>`;
            return isValid;
          };

          const moneiCardInput = monei.CardInput({
            paymentId: window.moneiPaymentId,
            onChange: () => { moneiCardErrors.innerHTML = ''; },
            onEnter: () => { moneiCardButton.click(); },
            language: prestashop.language.iso_code,
            style: window.moneiCardInputStyle || {},
          });
          moneiCardInput.render(moneiCardRenderContainer);

          moneiCardHolderName.addEventListener('blur', (event) => {
            validateMoneiCardHolderName(event.currentTarget.value);
          });

          moneiPaymentForm.addEventListener("submit", async (e) => {
            e.preventDefault();
            e.stopPropagation();

            if (!moneiPaymentForm.checkValidity() || !validateMoneiCardHolderName(moneiCardHolderName.value)) return;

            moneiCardButton.disabled = true;

            try {
              const { token, error } = await monei.createToken(moneiCardInput);
              if (!token) {
                moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error}</div>`;
                moneiCardButton.classList.remove('disabled');
                moneiCardButton.disabled = false;
                return;
              }

              moneiCardErrors.innerHTML = '';
              await moneiTokenHandler(token, moneiCardHolderName.value, moneiCardButton);
            } catch (error) {
              moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error.message}</div>`;
              moneiCardButton.classList.remove('disabled');
              moneiCardButton.disabled = false;
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
            paymentId: window.moneiPaymentId,
            style: window.moneiPaymentRequestStyle || {},
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
            paymentId: window.moneiPaymentId,
            style: window.moneiPaymentRequestStyle || {},
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