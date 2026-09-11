{**
 * Express checkout container.
 *
 * Markup only. views/js/front/express.js mounts a MONEI component into each
 * button slot and owns the whole flow from there.
 *
 * The error region is deliberately part of the container rather than something the
 * client creates on demand: a failed express payment has to be able to say so on
 * the surface the shopper started from, and a region that only exists on the happy
 * path is a region that is missing exactly when it is needed.
 *}
<div class="monei-express"
     data-monei-express
     data-location="{$moneiExpressLocation|escape:'htmlall':'UTF-8'}"
     data-product-id="{$moneiExpressProductId|intval}">
    {* Hidden until a button reports that it rendered: see reportLoaded() in
       express.js. A heading over an empty gap reads as a broken checkout. *}
    <div class="monei-express__label" data-monei-express-label hidden>{l s='Express checkout' mod='monei'}</div>

    {foreach from=$moneiExpressMethods item="moneiExpressMethod"}
        <div class="monei-express__button"
             data-monei-express-method="{$moneiExpressMethod|escape:'htmlall':'UTF-8'}"></div>
    {/foreach}

    <div class="monei-express__error" data-monei-express-error role="alert"></div>
</div>
