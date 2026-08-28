{**
 * MONEI payment method containers.
 *
 * Markup only. The JavaScript that used to live here inline, across six <script>
 * blocks, is now views/js/front/payment.js, and the values this template used to
 * interpolate into it are passed through Media::addJsDef in monei.php.
 *
 * Each container is what payment.js keys off: an init function returns early when
 * its container is absent, so rendering a section here is what activates that
 * payment method.
 *}
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
{/foreach}
