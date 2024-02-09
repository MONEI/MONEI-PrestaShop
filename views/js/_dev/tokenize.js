

document.getElementById('tokenize_card').addEventListener('change', function (event) {
    var forms = document.querySelectorAll('#payment-form');
    var check_status = this.checked;
    for (const form of forms) {
        var action = form.action;
        if (action.includes('monei_tokenize_card')) {
            form.action = action.replace(check_status ? 'monei_tokenize_card=0' : 'monei_tokenize_card=1',
                check_status ? 'monei_tokenize_card=1' : 'monei_tokenize_card=0');
        }
    }
});