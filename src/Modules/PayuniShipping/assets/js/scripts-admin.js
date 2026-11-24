(function( $ ) {
	'use strict';

	// 介面文字由 PHP 翻好傳進來（EnqueueScripts::admin 的 translations）；%s／%1$s／%2$s 依序代入。
	function t( key, a, b ) {
		var all = ( window.moksafowo_payuni_shipping && moksafowo_payuni_shipping.translations ) || {};
		var s   = all[ key ] || key;
		if ( undefined !== a ) {
			s = s.replace( '%1$s', a ).replace( '%s', a );
		}
		if ( undefined !== b ) {
			s = s.replace( '%2$s', b );
		}
		return s;
	}

	// 超商訂單隱藏街道地址欄的邏輯已移到共用層（Shipping/assets/js/admin-cvs-store.js），
	// 四家物流一致，不再綁 PAYUNi 專屬的欄位 id。

	// 列印 / 下載標籤 — 開新分頁送 PAYUNi（物流資訊卡片化後從這裡委派，原在 metabox inline JS）。
	$(document).on('click', '.print-label', function(){
		var newTab = window.open(ajaxurl + '?' + $.param({
			action: $(this).data('action'),
			orderids: $(this).data('id'),
			service: $(this).data('service'),
			security: moksafowo_payuni_shipping.security
		}), '_blank');
		setTimeout(function(){ if (newTab) { newTab.location.reload(); } }, 5000);
	});

	$(document).on('click', '.update-delivery-status', function(event){
		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'moksafowo_payuni_shipping_delivery_status',
				post_id: post_id,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function (data) {
				console.log(data);
				if (data.success) {
					window.location.reload();
				} else {
					alert(moksafowo_payuni_shipping.translations.shipping_status_update_failed + ' ' + data.result);
				}

				window.location.reload();

			},
			always: function () {}
		});

	});

	$(document).on('click', '.cancel-shipping', function (event) {

		event.preventDefault();
		var post_id = $(this).data('id');
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'cancel_shipping_order',
				post_id: post_id,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function ( data ) {
				console.log(data);
				if (data.success) {

				} else {
					alert( moksafowo_payuni_shipping.translations.cancel_shipping_failed + ' ' + data.result);
				}
				window.location.reload();

			},
			always: function () {}
		});

	});

	$(document).on('change', '#package-spec-select', function(event){
		event.preventDefault();
		var $select = $(this);
		var $cell = $select.closest('td');
		var order_id = $select.data('order-id');
		var package_spec = $select.val();
		var original_value = $select.data('original-value') || $select.find('option:selected').val();
		
		// Store original value if not already stored
		if (!$select.data('original-value')) {
			$select.data('original-value', original_value);
		}
		
		// Add loading indicator
		$select.prop('disabled', true);
		$cell.append('<span class="package-spec-loading" style="margin-left: 8px; color: #666;"><span class="spinner" style="visibility: visible; float: none; width: 16px; height: 16px; margin: 0;"></span> ' + moksafowo_payuni_shipping.translations.updating + '</span>');
		
		$.ajax({
			url: moksafowo_payuni_shipping.ajax_url,
			data: {
				action: 'moksafowo_payuni_shipping_update_package_spec',
				order_id: order_id,
				package_spec: package_spec,
				security: moksafowo_payuni_shipping.security,
			},
			dataType: "json",
			type: 'post',
			success: function (data) {
				console.log(data);
				if (data.success) {
					// Update original value to new value
					$select.data('original-value', package_spec);

					// 顯示成功後強制重載整頁 — 保證備註 / meta / 狀態全部與後端一致（WC metabox 標準做法）。
					$('.package-spec-loading').html('<span style="color: #46b450;">✓ ' + moksafowo_payuni_shipping.translations.updated + '</span>');
					setTimeout(function() {
						window.location.reload();
					}, 600);
				} else {
					// Show error and revert selection
					$('.package-spec-loading').html('<span style="color: #dc3232;">✗ ' + moksafowo_payuni_shipping.translations.update_failed + '</span>');
					$select.val(original_value); // Revert to original value
					
					// Show error message
					var error_msg = data.result || 'Unknown error';
					setTimeout(function() {
						$('.package-spec-loading').empty().append( $( '<span style="color: #dc3232;"></span>' ).attr( 'title', error_msg ).text( moksafowo_payuni_shipping.translations.update_failed ) );
						setTimeout(function() {
							$('.package-spec-loading').fadeOut(300, function() {
								$(this).remove();
							});
						}, 2000);
					}, 1000);
				}
			},
			error: function() {
				// Show error and revert selection
				$('.package-spec-loading').html('<span style="color: #dc3232;">✗ ' + moksafowo_payuni_shipping.translations.request_failed + '</span>');
				$select.val(original_value); // Revert to original value
				
				setTimeout(function() {
					$('.package-spec-loading').fadeOut(300, function() {
						$(this).remove();
					});
				}, 2000);
			},
			complete: function () {
				$select.prop('disabled', false);
			}
		});
	});

})( jQuery );