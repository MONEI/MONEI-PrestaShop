<div id="monei-card_container"></div>
<script>
  monei.CardInput({
    paymentId: '{$paymentId|escape:'htmlall':'UTF-8'}',
  })
  .render('#monei-card_container');
</script>