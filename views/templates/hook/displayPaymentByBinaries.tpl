<script>
  var moneiAccountId = '{$moneiAccountId|escape:'htmlall':'UTF-8'}';
  var moneiCreatePaymentUrlController = '{$moneiCreatePaymentUrlController|escape:'htmlall':'UTF-8'}';
  var moneiToken = '{$moneiToken|escape:'htmlall':'UTF-8'}';
  var moneiCurrency = '{$moneiCurrency|escape:'htmlall':'UTF-8'}';
  var moneiAmount = {$moneiAmount|intval};

  {literal}
    var moneiTokenHandler = async (parameters = {}) => {
      const { paymentToken, cardholderName = null, moneiConfirmationButton = null } = parameters;

      const createMoneiPayment = async () => {
        try {
          const response = await fetch(moneiCreatePaymentUrlController, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: moneiToken }),
          });

          if (!response.ok) throw new Error('Payment creation failed');

          const { moneiPaymentId } = await response.json();
          return moneiPaymentId;
        } catch (error) {
          Swal.fire({ title: 'Error', text: error.message, icon: 'error' });
          throw error;
        }
      };

      const params = { paymentToken };
      if (cardholderName) {
        params.paymentMethod = { card: { cardholderName } };
      }

      const saveCard = document.getElementById('monei-tokenize-card');
      if (saveCard?.checked) params.generatePaymentToken = true;

      Swal.fire({
        allowOutsideClick: false,
        allowEscapeKey: false,
        showConfirmButton: false,
        background: 'none',
        didOpen: async () => {
          Swal.showLoading();

          try {
            params.paymentId = await createMoneiPayment();
          } catch (error) {
            if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
            return;
          }

          try {
            const result = await monei.confirmPayment(params);
            handleMoneiTokenResult(result, moneiConfirmationButton);
          } catch (error) {
            handleMoneiTokenError(error, params, moneiConfirmationButton);
          }
        },
      });
    };

    var handleMoneiTokenResult = (result, moneiConfirmationButton) => {
      if (result.nextAction?.mustRedirect) {
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
              if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
            },
          });
        }
      }
    };

    var handleMoneiTokenError = (error, params, moneiConfirmationButton) => {
      Swal.fire({
        title: `${error.status} (${error.statusCode})`,
        text: error.message,
        icon: 'error',
        allowOutsideClick: false,
        allowEscapeKey: false,
        confirmButtonText: moneiMsgRetry,
        willClose: () => {
          if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
        },
      });
      console.log('moneiTokenHandler - error', params, error);
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
            moneiValidConditions() ? moneiEnableButton(moneiButton) : moneiDisableButton(moneiButton);
          });
        });
      }
    };

    var moneiEnableButton = (moneiButton) => {
      if (moneiButton) {
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
  <section class="js-payment-binary js-payment-monei js-payment-{$paymentOptionName|escape:'htmlall':'UTF-8'} mt-1 disabled">
    <div id="{$paymentOptionName|escape:'htmlall':'UTF-8'}-buttons-container">
      <form method="post">
        <input type="hidden" name="option" value="binary">
        <div class="{$paymentOptionName|escape:'htmlall':'UTF-8'}_render"></div>
        {if $paymentOptionName eq 'monei-card'}
          <button class="btn btn-primary btn-block w-100 mt-3" type="submit">
            <i class="material-icons">payment</i>
            {l s='Pay' mod='monei'}&nbsp;&nbsp;{$moneiAmountFormatted|escape:'htmlall':'UTF-8'}
          </button>
        {/if}
      </form>
    </div>
  </section>

  {if $paymentOptionName eq 'monei-bizum'}
    {literal}
      <script>
        var processingMoneiBizumPayment = false;

        function initMoneiBizum() {
          const moneiBizumButtonsContainer = document.getElementById('monei-bizum-buttons-container');
          if (!moneiBizumButtonsContainer) return;

          const moneiBizumRenderContainer = moneiBizumButtonsContainer.querySelector('.monei-bizum_render');
          if (!moneiBizumRenderContainer) return;

          monei.Bizum({
            accountId: moneiAccountId,
            style: moneiBizumStyle || {},
            onBeforeOpen() {
              if (!moneiValidConditions() || processingMoneiBizumPayment) {
                return false;
              }
              processingMoneiBizumPayment = true;
              return true;
            },
            onLoad() {
              processingMoneiBizumPayment = false;
            },
            onSubmit({token}) {
              if (token) {
                moneiTokenHandler({paymentToken: token});
              }
            },
            onError({status, statusCode, message}) {
              Swal.fire({
                title: `${status} (${statusCode})`,
                text: message,
                icon: 'error'
              });
              console.log('onError - Bizum', {status, statusCode, message});
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

          const moneiConfirmationButton = moneiPaymentForm.querySelector('button[type="submit"]');
          if (!moneiConfirmationButton) return;

          const moneiCardRenderContainer = document.getElementById('monei-card_container');
          if (!moneiCardRenderContainer) return;

          const moneiCardHolderName = document.getElementById('monei-card-holder-name');
          const moneiCardErrors = document.getElementById('monei-card-errors');

          moneiAddChangeEventToCheckboxes(moneiConfirmationButton);
          moneiValidConditions() ? moneiEnableButton(moneiConfirmationButton) : moneiDisableButton(moneiConfirmationButton);

          const validateMoneiCardHolderName = (name) => {
            const patternCardHolderName = /^[A-Za-zÀ-ú- ]{5,50}$/;
            const isValid = patternCardHolderName.test(name);
            moneiCardErrors.innerHTML = isValid ? '' : `<div class="alert alert-warning">${moneiCardHolderNameNotValid}</div>`;
            return isValid;
          };

          const moneiCardInput = monei.CardInput({
            accountId: moneiAccountId,
            onFocus: () => {
              moneiCardRenderContainer.classList.add('is-focused');
            },
            onBlur: () => {
              moneiCardRenderContainer.classList.remove('is-focused');
            },
            onChange: (event) => {
              // Handle real-time validation errors
              if (event.isTouched && event.error) {
                moneiCardRenderContainer.classList.add('is-invalid');
                moneiCardErrors.innerHTML = `<div class="alert alert-warning">${event.error}</div>`;
              } else {
                moneiCardRenderContainer.classList.remove('is-invalid');
                moneiCardErrors.innerHTML = '';
              }
            },
            onEnter: () => { moneiConfirmationButton.click(); },
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

            moneiDisableButton(moneiConfirmationButton);

            if (!moneiPaymentForm.checkValidity() || !validateMoneiCardHolderName(moneiCardHolderName.value)) {
              moneiEnableButton(moneiConfirmationButton);
              return;
            }

            try {
              const { token, error } = await monei.createToken(moneiCardInput);
              if (!token) {
                moneiCardRenderContainer.classList.add('is-invalid');
                moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error}</div>`;
                moneiEnableButton(moneiConfirmationButton);
                return;
              }

              moneiCardRenderContainer.classList.remove('is-invalid');
              moneiCardErrors.innerHTML = '';
              await moneiTokenHandler({ paymentToken: token, cardholderName: moneiCardHolderName.value, moneiConfirmationButton });
            } catch (error) {
              moneiCardRenderContainer.classList.add('is-invalid');
              moneiCardErrors.innerHTML = `<div class="alert alert-warning">${error.message}</div>`;
              moneiEnableButton(moneiConfirmationButton);
              console.log('createToken - Card Input - error', error);
            }
          });
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-googlePay'}
    {literal}
      <script>
        if (typeof window.ApplePaySession === 'undefined') {
          function initMoneiGooglePay() {
            const moneiPaymentRequestButtonsContainer = document.getElementById('monei-googlePay-buttons-container');
            if (!moneiPaymentRequestButtonsContainer) return;

            const moneiPaymentRequestRenderContainer = moneiPaymentRequestButtonsContainer.querySelector('.monei-googlePay_render');
            if (!moneiPaymentRequestRenderContainer) return;

            monei.PaymentRequest({
              accountId: moneiAccountId,
              style: moneiPaymentRequestStyle || {},
              amount: moneiAmount,
              currency: moneiCurrency,
              onBeforeOpen: moneiValidConditions,
              onSubmit(result) {
                if (result.token) moneiTokenHandler({ paymentToken: result.token });
              },
              onError(error) {
                Swal.fire({ title: `${error.status} (${error.statusCode})`, text: error.message, icon: 'error' });
                console.log('onError - Google Pay', error);
              }
            }).render(moneiPaymentRequestRenderContainer);
          }
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-applePay'}
    {literal}
      <script>
        if (window.ApplePaySession?.canMakePayments()) {
          function initMoneiApplePay() {
            const moneiPaymentRequestButtonsContainer = document.getElementById('monei-applePay-buttons-container');
            if (!moneiPaymentRequestButtonsContainer) return;

            const moneiPaymentRequestRenderContainer = moneiPaymentRequestButtonsContainer.querySelector('.monei-applePay_render');
            if (!moneiPaymentRequestRenderContainer) return;

            monei.PaymentRequest({
              accountId: moneiAccountId,
              style: moneiPaymentRequestStyle || {},
              amount: moneiAmount,
              currency: moneiCurrency,
              onBeforeOpen: moneiValidConditions,
              onSubmit(result) {
                if (result.token) moneiTokenHandler({ paymentToken: result.token });
              },
              onError(error) {
                Swal.fire({ title: `${error.status} (${error.statusCode})`, text: error.message, icon: 'error' });
                console.log('onError - Apple Pay', error);
              }
            }).render(moneiPaymentRequestRenderContainer);
          }
        } else {
          const moneiPaymentOption = document.querySelector('input[name="payment-option"][data-module-name="monei-applePay"]');
          if (moneiPaymentOption) {
              const moneiPaymentOptionParent = moneiPaymentOption.closest('.payment-option, .payment__option');
              if (moneiPaymentOptionParent) {
                  moneiPaymentOptionParent.style.setProperty('display', 'none', 'important');
              }
          }
        }
      </script>
    {/literal}
  {elseif $paymentOptionName eq 'monei-paypal'}
    {literal}
      <script>
        var processingMoneiPayPalPayment = false;

        function initMoneiPayPal() {
          const moneiPayPalButtonsContainer = document.getElementById('monei-paypal-buttons-container');
          if (!moneiPayPalButtonsContainer) return;

          const moneiPayPalRenderContainer = moneiPayPalButtonsContainer.querySelector('.monei-paypal_render');
          if (!moneiPayPalRenderContainer) return;

          monei.PayPal({
            accountId: moneiAccountId,
            language: prestashop.language.iso_code,
            style: moneiPayPalStyle || {},
            amount: moneiAmount,
            currency: moneiCurrency,
            onLoad() {
              processingMoneiPayPalPayment = false;
            },
            onBeforeOpen() {
              if (!moneiValidConditions() || processingMoneiPayPalPayment) {
                return false;
              }
              processingMoneiPayPalPayment = true;
              return true;
            },
            onSubmit(result) {
              if (result.error) {
                Swal.fire({
                  title: 'PayPal Error',
                  text: result.error.message || 'An error occurred with PayPal',
                  icon: 'error'
                });
                console.error('PayPal Error', result.error);
                processingMoneiPayPalPayment = false;
              } else if (result.token) {
                moneiTokenHandler({ paymentToken: result.token });
              }
            },
            onError(error) {
              Swal.fire({
                title: `${error.status || 'Error'} ${error.statusCode ? `(${error.statusCode})` : ''}`,
                text: error.message || 'An error occurred with PayPal',
                icon: 'error'
              });
              console.error('onError - PayPal', error);
              processingMoneiPayPalPayment = false;
            }
          }).render(moneiPayPalRenderContainer);
        }
      </script>
    {/literal}
  {/if}
{/foreach}