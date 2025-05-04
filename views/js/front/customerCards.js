document.addEventListener("DOMContentLoaded", function (event) {
    const cardsLinks = document.querySelectorAll('a[data-customer-card-id]');
    cardsLinks.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            Swal.fire({
                title: MoneiVars.titleRemoveCard || 'Remove Card',
                text: MoneiVars.textRemoveCard || 'Are you sure you want to remove this card?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: MoneiVars.confirmRemoveCard || 'Remove Card',
                cancelButtonText: MoneiVars.cancelRemoveCard || 'Cancel',
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
                text: MoneiVars.successfullyRemovedCard || 'Card removed successfully',
                icon: 'success'
            });

            itemCustomerCard.remove();
        } else {
            Swal.fire({
                title: 'Error',
                text: data.error || MoneiVars.errorRemovingCard || 'An error occurred while removing the card',
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
