jQuery(function ($) {
	'use strict';

	const { __, _x, _n, _nx } = wp.i18n;
	/**
	 * Object to handle LINE Pay admin functions.
	 */
	var linepay_admin = {
		/**
		 * Initialize.
		 */
		init: function () {
			$(document.body).on('change', '#Moksafowo_LinePay_sandboxmode_enabled', function () {
				var sandbox_channel_id = $('#Moksafowo_LinePay_sandbox_channel_id').parents('tr').eq(0),
					sandbox_channel_secret = $('#Moksafowo_LinePay_sandbox_channel_secret').parents('tr').eq(0),

					channel_id = $('#Moksafowo_LinePay_channel_id').parents('tr').eq(0),
					channel_secret = $('#Moksafowo_LinePay_channel_secret').parents('tr').eq(0);


				if ($(this).is(':checked')) {
					sandbox_channel_id.show();
					sandbox_channel_secret.show();

					channel_id.hide();
					channel_secret.hide();

				} else {
					sandbox_channel_id.hide();
					sandbox_channel_secret.hide();

					channel_id.show();
					channel_secret.show();

				}
			});

			$('#Moksafowo_LinePay_sandboxmode_enabled').trigger('change');

			$( document ).on( 'click', '.moksafowo-linepay-confirm-btn', function( event ){
				event.preventDefault();
				if ( ! window.confirm( moksafowo_linepay.confirm_msg ) ) {
					return;
				}
				var $btn = $(this);
				var post_id = $btn.data('id');
				$('.linepay-notice').remove();
				$btn.prop('disabled', true);
				if ($.blockUI) {
					$('#woocommerce-linepay-meta-boxes').block({
						message: null,
					});
				}
			$.ajax({
				url: moksafowo_linepay.ajax_url,
				data: {
					action: 'moksafowo_linepay_confirm',
					post_id: post_id,
					security: moksafowo_linepay.confirm_nonce,
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
					alert(moksafowo_linepay.error_msg);
					$btn.prop('disabled', false);
				},
				complete: function () {
					if ($.blockUI) {
						$('#woocommerce-linepay-meta-boxes').unblock();
					}
				}
			});

	});

		}
	};

	linepay_admin.init();
});
