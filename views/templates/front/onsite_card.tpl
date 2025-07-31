<div id="payment-form-monei">
    <div class="form-group">
        <input type="text" class="form-control" id="monei-card-holder-name" placeholder="{l s='Card holder name' mod='monei'}" required>
    </div>
    <div class="form-group">
        <div id="monei-card_container" class="form-control"></div>
    </div>
    <div id="monei-card-errors" class="form-group"></div>
    {if $isCustomerLogged && $tokenize}
        <ul class="form-group monei-tokenize-row">
            <li>
                <div class="float-xs-left">
                    <span class="custom-checkbox">
                        <input id="monei-tokenize-card" name="monei-tokenize-card" type="checkbox" value="1" class="ps-shown-by-js">
                        <span><i class="material-icons rtl-no-flip checkbox-checked"></i></span>
                    </span>
                </div>
                <div class="condition-label">
                    <label for="monei-tokenize-card">
                        {l s='Save Card details for future payments' mod='monei'}
                    </label>
                </div>
            </li>
        </ul>
    {/if}
</div>

{if $isCustomerLogged && $tokenize}
<script>
(function() {
    function setupMoneiCheckbox() {
        const checkbox = document.getElementById('monei-tokenize-card');
        if (!checkbox) return;
        
        const customCheckbox = checkbox.closest('.custom-checkbox');
        const label = document.querySelector('label[for="monei-tokenize-card"]');
        
        function toggleCheckbox(e) {
            e.preventDefault();
            e.stopPropagation();
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
        
        if (customCheckbox) {
            customCheckbox.style.cursor = 'pointer';
            customCheckbox.addEventListener('click', toggleCheckbox);
        }
        
        if (label) {
            label.style.cursor = 'pointer';
            label.addEventListener('click', toggleCheckbox);
        }
    }
    
    // Try to setup immediately
    setupMoneiCheckbox();
    
    // Also try after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupMoneiCheckbox);
    }
    
    // And try with a small delay in case elements are added dynamically
    setTimeout(setupMoneiCheckbox, 100);
})();
</script>
{/if}