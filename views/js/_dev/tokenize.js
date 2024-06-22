const tokenize_card = document.getElementById('tokenize_card');
if (tokenize_card) {
    tokenize_card.addEventListener('change', function (event) {
        const forms = document.querySelectorAll('#payment-form');
        const check_status = this.checked;
        for (const form of forms) {
            const action = form.action;
            if (action.includes('monei_tokenize_card')) {
                form.action = action.replace(check_status ? 'monei_tokenize_card=0' : 'monei_tokenize_card=1', check_status ? 'monei_tokenize_card=1' : 'monei_tokenize_card=0');
            }
        }
    });
}