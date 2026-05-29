( function ( $ ) {
	'use strict';
	if ( ! window.mo_ecpay_shipping_admin ) {
		return;
	}
	const cfg = window.mo_ecpay_shipping_admin;

	function getNonce( $box ) {
		return $box.find( '#mo_ecpay_shipping_nonce' ).val();
	}

	function getOrderId( $box ) {
		return $box.data( 'order-id' );
	}

	$( document ).on( 'click', '.mo-ecpay-shipping-create', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		const $box = $btn.closest( '.mo-ecpay-shipping-meta' );
		const orderId = getOrderId( $box );
		const nonce = getNonce( $box );
		if ( ! orderId ) {
			alert( cfg.i18n.no_order );
			return;
		}
		// H1 防呆：已有記錄時點「重新建立」先二次確認（伺服端另有 per-temp 冪等保護）
		if ( $btn.data( 'has-records' ) && ! window.confirm( cfg.i18n.recreate_confirm ) ) {
			return;
		}
		// 先存原始文字再覆寫，否則錯誤路徑還原時 data('orig') 為 undefined → 按鈕文字被清空。
		$btn.prop( 'disabled', true ).data( 'orig', $btn.text() ).text( cfg.i18n.creating );
		$.post( cfg.ajax_url, {
			action: 'mo_ecpay_shipping_create_order',
			order_id: orderId,
			nonce: nonce,
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				alert( cfg.i18n.create_ok );
				location.reload();
			} else {
				alert( cfg.i18n.create_fail + ( ( resp && resp.data && resp.data.message ) || cfg.i18n.unknown_error ) );
				$btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || '' );
			}
		} ).fail( function () {
			alert( cfg.i18n.create_fail + cfg.i18n.ajax_error );
			$btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || '' );
		} );
	} );

	$( document ).on( 'click', '.mo-ecpay-shipping-print', function ( e ) {
		e.preventDefault();
		const $btn = $( this );
		const $box = $btn.closest( '.mo-ecpay-shipping-meta' );
		const orderId = getOrderId( $box );
		const nonce = getNonce( $box );
		const logisticsId = $btn.data( 'logistics-id' ) || '';
		const mode = String( $btn.data( 'mode' ) || '1' );
		$btn.prop( 'disabled', true ).data( 'orig', $btn.text() ).text( cfg.i18n.printing || cfg.i18n.creating );
		$.post( cfg.ajax_url, {
			action: 'mo_ecpay_shipping_print_label',
			order_id: orderId,
			nonce: nonce,
			logistics_id: logisticsId,
			mode: mode,
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && resp.data.api_url ) {
				submitForm( resp.data.api_url, resp.data.form_data );
				$btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || '' );
			} else {
				alert( cfg.i18n.print_fail + ( ( resp && resp.data && resp.data.message ) || cfg.i18n.unknown_error || '' ) );
				$btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || '' );
			}
		} ).fail( function () {
			alert( cfg.i18n.print_fail + ( cfg.i18n.ajax_error || 'AJAX error' ) );
			$btn.prop( 'disabled', false ).text( $btn.data( 'orig' ) || '' );
		} );
	} );

	$( document ).on( 'click', '.mo-ecpay-shipping-delete-record', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( cfg.i18n.delete_confirm ) ) {
			return;
		}
		const $btn = $( this );
		const $box = $btn.closest( '.mo-ecpay-shipping-meta' );
		const orderId = getOrderId( $box );
		const nonce = getNonce( $box );
		const logisticsId = $btn.data( 'logistics-id' );
		$btn.prop( 'disabled', true );
		$.post( cfg.ajax_url, {
			action: 'mo_ecpay_shipping_delete_record',
			order_id: orderId,
			nonce: nonce,
			logistics_id: logisticsId,
		} ).done( function ( resp ) {
			if ( resp && resp.success ) {
				alert( cfg.i18n.delete_ok );
				location.reload();
			} else {
				alert( cfg.i18n.delete_fail + ( ( resp && resp.data && resp.data.message ) || cfg.i18n.unknown_error ) );
				$btn.prop( 'disabled', false );
			}
		} ).fail( function () {
			alert( cfg.i18n.delete_fail + cfg.i18n.ajax_error );
			$btn.prop( 'disabled', false );
		} );
	} );

	function submitForm( apiUrl, formData ) {
		// write form HTML 進預先 open 的 window，inline script auto-submit，只開一個 tab。
		const w = window.open( '', '_blank' );
		if ( ! w ) {
			alert( '瀏覽器封鎖彈出視窗，請允許本站開啟新視窗。' );
			return;
		}
		let html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>printing…</title></head><body>';
		html += '<form id="f" method="post" action="' + escapeAttr( apiUrl ) + '">';
		Object.keys( formData ).forEach( function ( k ) {
			html += '<input type="hidden" name="' + escapeAttr( k ) + '" value="' + escapeAttr( formData[ k ] ) + '">';
		} );
		html += '</form><script>document.getElementById("f").submit();<\/script></body></html>';
		w.document.open();
		w.document.write( html );
		w.document.close();
	}

	function escapeAttr( s ) {
		return String( s == null ? '' : s ).replace( /&/g, '&amp;' ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}
} )( jQuery );
