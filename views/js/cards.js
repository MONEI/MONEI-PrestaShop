document.addEventListener("DOMContentLoaded", function (event) {
    var cards_links = document.querySelectorAll('a[data-monei-card]');
    cards_links.forEach(function (link) {
        link.addEventListener('click', function (event) {
            event.preventDefault();
            swal({
                title: monei_title_remove_card,
                text: monei_text_remove_card,
                icon: 'warning',
                dangerMode: true,
                buttons: [monei_cancel_remove_card, monei_confirm_remove_card],
            }).then((result) => {
                if (result) {
                    this.parentElement.parentElement.remove();
                    ajaxDeleteTokenizedCard(this.dataset.moneiCard);
                    swal(monei_title_remove_card, monei_successfully_removed_card, 'success');
                }
            });
        });
    });
});

function ajaxDeleteTokenizedCard(id_monei_card) {
    var url_delete_monei_card = new URL(monei_index_url);
    var params = {
        fc: 'module',
        module: 'monei',
        controller: 'cards',
        action: 'deletecard',
        id_monei_card: id_monei_card,
        ajax: true
    };

    url_delete_monei_card.search = new URLSearchParams(params).toString();

    fetch(url_delete_monei_card, {
        method: 'POST',
        headers: {
            'Accept': 'application/json'
        }
    });
}


