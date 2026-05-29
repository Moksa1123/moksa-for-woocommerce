jQuery(function ($) {
	'use strict';

	const { __, _x, _n, _nx } = wp.i18n;
	/**
	 * Object to handle PAYUNi admin functions.
	 */
	var mo_payuni_admin = {
		/**
		 * Initialize.
		 */
		init: function () {

			$(document.body).on('change', '#payuni_payment_testmode_enabled', function () {
				var sandbox_merchant_id = $('#payuni_payment_merchant_id_test').parents('tr').eq(0),
					sandbox_hashkey = $('#payuni_payment_hashkey_test').parents('tr').eq(0),
                    sandbox_ivkey = $('#payuni_payment_hashiv_test').parents('tr').eq(0),

					merchant_id = $('#payuni_payment_merchant_id').parents('tr').eq(0),
					hashkey = $('#payuni_payment_hashkey').parents('tr').eq(0),
                    ivkey = $('#payuni_payment_hashiv').parents('tr').eq(0);


				if ($(this).is(':checked')) {
					sandbox_merchant_id.show();
					sandbox_hashkey.show();
                    sandbox_ivkey.show();

					merchant_id.hide();
					hashkey.hide();
                    ivkey.hide();

				} else {
					sandbox_merchant_id.hide();
					sandbox_hashkey.hide();
                    sandbox_ivkey.hide();

					merchant_id.show();
					hashkey.show();
                    ivkey.show();

				}
			});

			$('#payuni_payment_testmode_enabled').trigger('change');

			$( document ).on( 'click', '#payuni-query-btn', function( event ){
				event.preventDefault();
				var $btn = $(this);
				var order_id = $btn.data('id');
				$btn.prop('disabled', true);
				if ($.blockUI) {
					$('#payuni-order-meta-boxes').block({
						message: null,
					});
				}
				$.ajax({
					url: mo_payuni.ajax_url,
					data: {
						action: 'payuni_query',
						order_id: order_id,
						security: mo_payuni.query_nonce,
					},
					dataType: "json",
					type: 'post',
					success: function (data) {
						if (data.success) {
							alert(data.message);
							window.location.reload();
							return;
						}
						alert(data.message);
						$btn.prop('disabled', false);
					},
					error: function () {
						alert(mo_payuni.error_msg);
						$btn.prop('disabled', false);
					},
					complete: function () {
						if ($.blockUI) {
							$('#payuni-order-meta-boxes').unblock();
						}
					}
				});//ajax

			});

        }//init
    };

	mo_payuni_admin.init();
});
