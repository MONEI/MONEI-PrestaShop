<script>
  var moneiAccountId = '{$moneiAccountId|escape:'htmlall':'UTF-8'}';
  var moneiCreatePaymentUrlController = '{$moneiCreatePaymentUrlController|escape:'htmlall':'UTF-8'}';
  var moneiToken = '{$moneiToken|escape:'htmlall':'UTF-8'}';
  var moneiCurrency = '{$moneiCurrency|escape:'htmlall':'UTF-8'}';
  var moneiAmount = {$moneiAmount|intval};
  var moneiPaymentAction = '{if isset($moneiPaymentAction)}{$moneiPaymentAction|escape:'htmlall':'UTF-8'}{else}sale{/if}';

  {literal}
    // Debug logging helper - only logs in development/test mode
    var moneiLog = function(level, component, message, data) {
      // Only log if not in production (check for debug mode, test environment, etc.)
      if (window.location.hostname === 'localhost' || 
          window.location.hostname.includes('test') || 
          window.location.hostname.includes('dev') ||
          window.location.search.includes('debug=1')) {
        const timestamp = new Date().toISOString();
        const logMessage = `[MONEI ${timestamp}] [${component}] ${message}`;
        
        if (level === 'error') {
          console.error(logMessage, data || '');
        } else {
          console.log(logMessage, data || '');
        }
      }
    };
    
    // Reusable AJAX request handler with error handling
    var moneiAjaxRequest = async function(url, options = {}) {
      const defaultOptions = {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        ...options
      };
      
      try {
        moneiLog('info', 'Ajax', `Making request to ${url}`, defaultOptions);
        const response = await fetch(url, defaultOptions);
        const data = await response.json();
        
        if (!response.ok) {
          // Extract error message from response
          const errorMessage = data.message || data.error || 'An error occurred';
          moneiLog('error', 'Ajax', `Request failed: ${errorMessage}`, data);
          
          // Display error to user
          showMoneiError(errorMessage);
          
          // Throw error for caller to handle if needed
          const error = new Error(errorMessage);
          error.response = response;
          error.data = data;
          throw error;
        }
        
        moneiLog('info', 'Ajax', 'Request successful', data);
        return data;
        
      } catch (error) {
        // Handle network errors or JSON parsing errors
        if (!error.response) {
          const networkError = 'Network error. Please check your connection and try again.';
          moneiLog('error', 'Ajax', networkError, error);
          showMoneiError(networkError);
          throw new Error(networkError);
        }
        // Re-throw if it's already a handled error
        throw error;
      }
    };
    
    // Show loading overlay
    var showMoneiLoading = function() {
      // Create loading overlay
      const overlay = document.createElement('div');
      overlay.id = 'monei-loading-overlay';
      overlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
      overlay.innerHTML = '<div style="background: white; padding: 30px; border-radius: 8px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">' +
                         '<div style="margin-bottom: 15px;">' +
                         '<div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #f3f3f3; border-top: 4px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite;"></div>' +
                         '</div>' +
                         '<div style="font-size: 16px; color: #333;">' + 
                         (typeof moneiProcessingPayment !== 'undefined' ? moneiProcessingPayment : 'Processing payment...') + 
                         '</div></div>';
      
      // Add CSS animation
      const style = document.createElement('style');
      style.textContent = '@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }';
      document.head.appendChild(style);
      
      document.body.appendChild(overlay);
      
      // Also disable payment confirmation button
      const confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
      if (confirmButton) {
        confirmButton.disabled = true;
        confirmButton.classList.add('disabled');
      }
    };
    
    // Hide loading state
    var hideMoneiLoading = function() {
      // Remove loading overlay
      const overlay = document.getElementById('monei-loading-overlay');
      if (overlay) {
        overlay.remove();
      }
      
      // Re-enable payment confirmation button
      const confirmButton = document.querySelector('#payment-confirmation button[type="submit"]');
      if (confirmButton) {
        confirmButton.disabled = false;
        confirmButton.classList.remove('disabled');
      }
    };
    
    // Show error using PrestaShop's native notification structure
    var showMoneiError = function(message) {
      hideMoneiLoading();
      
      // Find the existing notifications container
      let notificationContainer = document.querySelector('#notifications');
      
      if (notificationContainer) {
        // Check if notifications container already has a container class div
        let containerDiv = notificationContainer.querySelector('.container, .notifications-container');
        
        if (!containerDiv) {
          // Add container div to match page width
          containerDiv = document.createElement('div');
          containerDiv.className = 'container';
        } else {
          // Clear existing content
          containerDiv.innerHTML = '';
        }
        
        // Create the alert structure
        const alert = document.createElement('article');
        alert.className = 'alert alert-danger';
        alert.setAttribute('role', 'alert');
        alert.setAttribute('data-alert', 'danger');
        
        const list = document.createElement('ul');
        const listItem = document.createElement('li');
        listItem.textContent = message;
        
        list.appendChild(listItem);
        alert.appendChild(list);
        
        // Add alert to container
        containerDiv.appendChild(alert);
        
        // If we created a new container, add it to notifications
        if (!notificationContainer.querySelector('.container, .notifications-container')) {
          notificationContainer.innerHTML = '';
          notificationContainer.appendChild(containerDiv);
        }
        
        // Scroll to the notification
        notificationContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else {
        // If no notifications container exists, insert alert in the payment section
        const paymentSection = document.querySelector('#checkout-payment-step, .checkout-step.-current, .payment-options');
        
        if (paymentSection) {
          // Remove any existing MONEI alerts
          const existingAlert = paymentSection.querySelector('.monei-payment-alert');
          if (existingAlert) {
            existingAlert.remove();
          }
          
          // Create alert
          const alert = document.createElement('div');
          alert.className = 'alert alert-danger monei-payment-alert';
          alert.setAttribute('role', 'alert');
          
          const list = document.createElement('ul');
          const listItem = document.createElement('li');
          listItem.textContent = message;
          
          list.appendChild(listItem);
          alert.appendChild(list);
          
          // Insert at the top of payment section
          paymentSection.insertBefore(alert, paymentSection.firstChild);
          
          // Scroll to the alert
          alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
          // Last resort: use JavaScript alert
          alert(message);
        }
      }
    };

    var moneiTokenHandler = async (parameters = {}) => {
      const { paymentToken, cardholderName = null, moneiConfirmationButton = null } = parameters;

      const createMoneiPayment = async () => {
        try {
          const data = await moneiAjaxRequest(moneiCreatePaymentUrlController, {
            body: JSON.stringify({ token: moneiToken })
          });
          
          return data.moneiPaymentId;
        } catch (error) {
          // Error is already displayed by moneiAjaxRequest
          throw error;
        }
      };

      const params = { paymentToken };
      if (cardholderName) {
        params.paymentMethod = { card: { cardholderName } };
      }

      const saveCard = document.getElementById('monei-tokenize-card');
      if (saveCard?.checked) params.generatePaymentToken = true;

      showMoneiLoading();

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
    };

    var handleMoneiTokenResult = (result, moneiConfirmationButton) => {
      if (result.nextAction?.mustRedirect) {
        location.assign(result.nextAction.redirectUrl);
      } else if (result.nextAction?.redirectUrl) {
        // Always redirect to complete URL for unified flow - let confirmation controller handle success/failure
        location.assign(result.nextAction.redirectUrl);
      } else {
        // Fallback for cases without redirectUrl (shouldn't happen with single complete URL approach)
        hideMoneiLoading();
        showMoneiError(result.statusMessage || (typeof moneiPaymentProcessed !== 'undefined' ? moneiPaymentProcessed : 'Payment processed'));
        if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
      }
    };

    var handleMoneiTokenError = (error, params, moneiConfirmationButton) => {
      // Check if error response has redirectUrl for unified flow
      if (error.nextAction?.redirectUrl) {
        location.assign(error.nextAction.redirectUrl);
      } else {
        // Fallback to showing error
        hideMoneiLoading();
        showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
        if (moneiConfirmationButton) moneiEnableButton(moneiConfirmationButton);
      }
      moneiLog('error', 'TokenHandler', 'Payment error occurred', { params, error });
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
              showMoneiError(`${status} (${statusCode}): ${message}`);
              moneiLog('error', 'Bizum', `Payment error: ${status} (${statusCode})`, { status, statusCode, message });
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
              moneiLog('error', 'CardInput', 'Failed to create token', error);
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
                showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
                moneiLog('error', 'GooglePay', `Payment error: ${error.status} (${error.statusCode})`, error);
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
                showMoneiError(`${error.status} (${error.statusCode}): ${error.message}`);
                moneiLog('error', 'ApplePay', `Payment error: ${error.status} (${error.statusCode})`, error);
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

          var paypalConfig = {
            accountId: moneiAccountId,
            language: prestashop.language.iso_code,
            style: moneiPayPalStyle || {},
            amount: moneiAmount,
            currency: moneiCurrency,
            transactionType: moneiPaymentAction === 'auth' ? 'AUTH' : 'SALE',
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
                showMoneiError(result.error.message || (typeof moneiErrorOccurredWithPayPal !== 'undefined' ? moneiErrorOccurredWithPayPal : 'An error occurred with PayPal'));
                moneiLog('error', 'PayPal', 'Payment submission error', result.error);
                processingMoneiPayPalPayment = false;
              } else if (result.token) {
                moneiTokenHandler({ paymentToken: result.token });
              }
            },
            onError(error) {
              showMoneiError(`${error.status || (typeof moneiErrorOccurred !== 'undefined' ? moneiErrorOccurred : 'Error')} ${error.statusCode ? `(${error.statusCode})` : ''}: ${error.message || (typeof moneiErrorOccurredWithPayPal !== 'undefined' ? moneiErrorOccurredWithPayPal : 'An error occurred with PayPal')}`);
              moneiLog('error', 'PayPal', `Payment error: ${error.status || 'Unknown'} ${error.statusCode ? `(${error.statusCode})` : ''}`, error);
              processingMoneiPayPalPayment = false;
            }
          };
          
          monei.PayPal(paypalConfig).render(moneiPayPalRenderContainer);
        }
      </script>
    {/literal}
  {/if}
{/foreach}