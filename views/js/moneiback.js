(function ($) {
    $(document).ready(function () {
        $('a.fancybox').fancybox({
            helpers: {
                title: {type: 'inside', position: 'top'}
            },
            width: '90%',
            height: '90%',
            type: 'html',
            overlay: {
                showEarly: false
            },
            beforeShow: function () {
                const json_info_coded = atob($(this.element).data("moneijson"));
                $('#json_log').jsonViewer(JSON.parse(json_info_coded), {
                    collapsed: true,
                    rootCollapsable: false
                });
                $.fancybox.update();
            },
            afterLoad: function () {
                this.content = '<pre id="json_log" class="json-document"></pre>';
            },
            afterShow: function () {

            }
        });

        $('body').on('change', '#monei_refund_amount', function () {
            const max_to_refund = $(this).data('maxrefund');
            const desired_refund = $(this).val();
            if (desired_refund > max_to_refund) {
                $('#moneiAlert').removeClass('collapse');
            } else {
                $('#moneiAlert').addClass('collapse');
            }
        });

        $('body').on('change', '#monei_refund_reason, #monei_refund_amount', function () {
            if ($("#monei_refund_reason option:selected").index() == 0 || $('#monei_refund_amount').val() == 0) {
                $('#moneiBtnRefund').attr('disabled', 'disabled').addClass('disabled');
            } else {
                $('#moneiBtnRefund').removeAttr('disabled').removeClass('disabled');
            }
        });

        $('body').on('click', '#moneiBtnRefund', function () {
            swal({
                title: monei_title_refund,
                text: monei_text_refund,
                icon: 'warning',
                dangerMode: true,
                buttons: [monei_cancel_refund, monei_confirm_refund],
            }).then((result) => {
                if (result) {
                    makeARefund();
                }
            });
        });

        $('body').on('click', '#moneiBtnRefund', function () {
        });

        function makeARefund() {
            $('#moneiBtnRefund').attr('disabled', 'disabled').addClass('disabled');
            $('body').css('opacity', '0.5');
            $.ajax({
                type: 'POST',
                url: 'ajax-tab.php',
                data: {
                    controller: 'AdminMonei',
                    action: 'refund',
                    ajax: true,
                    token: admin_monei_token,
                    id_order: $('#monei_order_id').val(),
                    amount: $('#monei_refund_amount').val(),
                    reason: $('#monei_refund_reason').val(),
                },
                dataType: 'json',
                success: function (json_response) {
                    swal(monei_title_refund, json_response['message'], 'success')
                        .then((value) => {
                            location.reload();
                        });

                },
                error: function (xJHR, textStatus, errorThrown) {
                    swal(monei_title_refund, xJHR.responseJSON.message, 'error');
                },
                complete: function (json_response) {
                    $('body').css('opacity', '1');
                    $('#moneiBtnRefund').attr('disabled', '').removeClass('disabled');
                }
            });
        }
    });
})(jQuery);