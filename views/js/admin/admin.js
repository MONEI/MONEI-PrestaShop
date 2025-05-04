(function ($) {
    $(document).ready(function () {
        $('body').on('change', 'input[name="MONEI_PRODUCTION_MODE"]:checked', function () {
            if ($(this).val() === '1') {
                $('#MONEI_API_KEY').parents('.form-group').show();
                $('#MONEI_ACCOUNT_ID').parents('.form-group').show();
                $('#MONEI_TEST_API_KEY').parents('.form-group').hide();
                $('#MONEI_TEST_ACCOUNT_ID').parents('.form-group').hide();
            } else {
                $('#MONEI_API_KEY').parents('.form-group').hide();
                $('#MONEI_ACCOUNT_ID').parents('.form-group').hide();
                $('#MONEI_TEST_API_KEY').parents('.form-group').show();
                $('#MONEI_TEST_ACCOUNT_ID').parents('.form-group').show();
            }
        });
        $('input[name="MONEI_PRODUCTION_MODE"]').trigger('change');

        if (typeof MoneiVars === 'undefined') {
            return;
        }

        $('a.fancybox').fancybox({
            helpers: {
                title: { type: 'inside', position: 'top' }
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
            }
        });

        $('body').on('change', '#monei_refund_amount', function () {
            const max_to_refund = $(this).data('maxrefund');
            const desired_refund = $(this).val();
            $('#moneiAlert').toggleClass('collapse', desired_refund <= max_to_refund);
        });

        $('body').on('change', '#monei_refund_reason, #monei_refund_amount', function () {
            const isDisabled =
                $("#monei_refund_reason option:selected").index() === 0 ||
                parseFloat($('#monei_refund_amount').val()) <= 0;

            $('#moneiBtnRefund').attr('disabled', isDisabled).toggleClass('disabled', isDisabled);
        });

        $('body').on('click', '#moneiBtnRefund', function () {
            Swal.fire({
                title: MoneiVars.titleRefund,
                text: MoneiVars.textRefund,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: MoneiVars.confirmRefund,
                cancelButtonText: MoneiVars.cancelRefund,
            }).then((result) => {
                if (result.isConfirmed) {
                    makeARefund();
                }
            });
        });

        function makeARefund() {
            $('#moneiBtnRefund').attr('disabled', 'disabled').addClass('disabled');
            $('body').css('opacity', '0.5');
            $.ajax({
                type: 'POST',
                url: MoneiVars.adminMoneiControllerUrl,
                data: {
                    controller: 'AdminMonei',
                    action: 'refund',
                    ajax: true,
                    token: MoneiVars.adminMoneiToken,
                    id_order: $('#monei_order_id').val(),
                    amount: $('#monei_refund_amount').val(),
                    reason: $('#monei_refund_reason').val(),
                },
                dataType: 'json',
                success: function (json_response) {
                    Swal.fire(MoneiVars.titleRefund, json_response['message'], 'success')
                        .then(() => {
                            location.reload();
                        });
                },
                error: function (xJHR) {
                    Swal.fire(MoneiVars.titleRefund, xJHR.responseJSON.message, 'error');
                },
                complete: function () {
                    $('body').css('opacity', '1');
                    $('#moneiBtnRefund').attr('disabled', '').removeClass('disabled');
                }
            });
        }
    });
})(jQuery);