( function ( $ ) {
	'use strict';

	var cfg = window.moksafowo_payuni_returns || {};

	function message( $box, text, ok ) {
		$box.find( '.moksafowo-payuni-return-message' )
			.empty()
			.append( $( '<span></span>' ).css( 'color', ok ? '#008a20' : '#d63638' ).text( text ) );
	}

	function send( $box, data, $button ) {
		$button.prop( 'disabled', true );
		message( $box, cfg.i18n.working, true );
		data.security = cfg.nonce;
		data.order_id = $box.data( 'order-id' );
		$.post( cfg.ajax_url, data )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
					return;
				}
				message( $box, ( res && res.data && res.data.message ) || cfg.i18n.failed, false );
				$button.prop( 'disabled', false );
			} )
			.fail( function ( xhr ) {
				var res = xhr.responseJSON;
				message( $box, ( res && res.data && res.data.message ) || cfg.i18n.failed, false );
				$button.prop( 'disabled', false );
			} );
	}

	// 欄位放在訂單編輯表單裡，不用 name（避免跟著訂單一起送出），改用 data-field。
	// 建立退貨：統一金流沒有取消 API，送出前再確認一次。
	$( document ).on( 'click', '.moksafowo-payuni-return-submit', function ( event ) {
		event.preventDefault();
		var $button = $( this );
		var $form   = $button.closest( '.moksafowo-payuni-return-form' );
		var $box    = $form.closest( '.moksafowo-payuni-returns' );
		if ( ! window.confirm( cfg.i18n.confirm ) ) {
			return;
		}
		var data = { action: 'moksafowo_payuni_return_create', type: $form.data( 'type' ) };
		$form.find( '[data-field]' ).each( function () {
			var key = $( this ).data( 'field' );
			if ( 'checkbox' === this.type ) {
				data[ key ] = this.checked ? '1' : '';
			} else {
				data[ key ] = $( this ).val();
			}
		} );
		send( $box, data, $button );
	} );

	// 只有「顧客在門市付費」才要填金額。
	function toggleAmount( $form ) {
		var paid = '4' === $form.find( '[data-field="service_type"]' ).val();
		$form.find( '[data-field="ship_amt"]' ).closest( 'label' ).toggle( paid );
	}
	$( function () {
		$( '.moksafowo-payuni-return-form' ).each( function () {
			toggleAmount( $( this ) );
		} );
	} );
	$( document ).on( 'change', '.moksafowo-payuni-return-form [data-field="service_type"]', function () {
		toggleAmount( $( this ).closest( '.moksafowo-payuni-return-form' ) );
	} );

	// Enter 不要送出整張訂單表單。
	$( document ).on( 'keydown', '.moksafowo-payuni-return-form input', function ( event ) {
		if ( 13 === event.which ) {
			event.preventDefault();
		}
	} );

	$( document ).on( 'click', '.moksafowo-payuni-return-query', function ( event ) {
		event.preventDefault();
		var $btn = $( this );
		send( $btn.closest( '.moksafowo-payuni-returns' ), { action: 'moksafowo_payuni_return_query', ref: $btn.data( 'ref' ) }, $btn );
	} );
} )( jQuery );
