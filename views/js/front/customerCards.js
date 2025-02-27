document.addEventListener("DOMContentLoaded", function (event) {
    const cardsLinks = document.querySelectorAll('a[data-customer-card-id]');
    cardsLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            Swal.fire({
                title: MoneiVars.titleRemoveCard,
                text: MoneiVars.textRemoveCard,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: MoneiVars.confirmRemoveCard,
                cancelButtonText: MoneiVars.cancelRemoveCard,
            }).then((result) => {
                if (result.isConfirmed) {
                    ajaxDeleteTokenizedCard(this.dataset.customerCardId, this.parentElement.parentElement);
                }
            });
        });
    });
});

function ajaxDeleteTokenizedCard(customerCardId, itemCustomerCard) {
    const deleteCustomerCardUrl = new URL(MoneiVars.indexUrl);

    const params = {
        fc: 'module',
        module: 'monei',
        controller: 'customerCards',
        action: 'deleteCustomerCard',
        ajax: true,
        customerCardId
    };

    deleteCustomerCardUrl.search = new URLSearchParams(params).toString();

    fetch(deleteCustomerCardUrl, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                title: 'Success',
                text: MoneiVars.successfullyRemovedCard,
                icon: 'success'
            });

            itemCustomerCard.remove();
        } else {
            Swal.fire({
                title: 'Error',
                text: data.error || MoneiVars.errorRemovingCard,
                icon: 'error'
            });
        }
    })
    .catch(error => {
        Swal.fire({
            title: 'Error',
            text: 'An unexpected error occurred.',
            icon: 'error'
        });
    });
}
