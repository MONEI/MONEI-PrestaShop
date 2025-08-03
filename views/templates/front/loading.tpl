{extends file='page.tpl'}

{block name='page_content_container'}
<div class="monei-loading-container text-center py-5">
    <div class="spinner-border text-primary mb-3" role="status">
        <span class="sr-only">{l s='Loading...' mod='monei'}</span>
    </div>
    
    <h3 class="mb-3">{l s='Processing Payment' mod='monei'}</h3>
    <p class="mb-4">{$loading_message}</p>
    
    <div class="alert alert-info">
        <p class="mb-0">{l s='Please do not close this window or navigate away from this page.' mod='monei'}</p>
    </div>
</div>

<script>
// Automatically check payment status every 3 seconds
(function() {
    var paymentId = '{$payment_id|escape:'javascript':'UTF-8'}';
    var completeUrl = '{$complete_url|escape:'javascript':'UTF-8'}';
    var maxAttempts = 60; // 3 minutes maximum
    var attempts = 0;
    
    function checkPaymentStatus() {
        attempts++;
        
        if (attempts >= maxAttempts) {
            // Redirect to complete URL after maximum attempts
            window.location.href = completeUrl + '?id=' + encodeURIComponent(paymentId);
            return;
        }
        
        // Make a simple request to check if payment is still pending
        // For now, just redirect after a timeout - this could be enhanced with AJAX
        setTimeout(function() {
            window.location.href = completeUrl + '?id=' + encodeURIComponent(paymentId);
        }, 3000);
    }
    
    // Start checking after 3 seconds
    setTimeout(checkPaymentStatus, 3000);
})();
</script>
{/block}