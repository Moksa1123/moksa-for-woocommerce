( function ( $ ) {
	'use strict';
	if ( ! window.mo_ecpay_invoice_admin ) {
		return;
	}
	const cfg = window.mo_ecpay_invoice_admin;

	function ctx( $btn ) {
		const $box = $btn.closest( '.mo-ecpay-invoice-meta' );
		return {
			$btn:  $btn,
			$box:  $box,
			order: $box.data( 'order-id' ),
			nonce: $box.find( '#mo_ecpay_invoice_nonce' ).val(),
		};
	}

	function fail( prefix, resp ) {
		return prefix + ( ( resp && resp.data && resp.data.message ) || cfg.i18n.unknown_error );
	}

	// 共用送出流程：送出前 disable + 文案，失敗復原；不可逆動作先二次確認。
	function run( c, payload, okMsg, failPrefix, busyText ) {
		c.$btn.prop( 'disabled', true );
		if ( busyText ) {
			c.$btn.data( 'orig', c.$btn.text() ).text( busyText );
		}
		$.post( cfg.ajax_url, payload ).done( function ( resp ) {
			if ( resp && resp.success ) {
				alert( okMsg );
				location.reload();
			} else {
				alert( fail( failPrefix, resp ) );
				c.$btn.prop( 'disabled', false );
				if ( busyText ) {
					c.$btn.text( c.$btn.data( 'orig' ) || '' );
				}
			}
		} ).fail( function () {
			alert( fail( failPrefix, null ) );
			c.$btn.prop( 'disabled', false );
			if ( busyText ) {
				c.$btn.text( c.$btn.data( 'orig' ) || '' );
			}
		} );
	}

	$( document ).on( 'click', '.mo-ecpay-invoice-issue', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		run( c, {
			action: 'mo_ecpay_invoice_issue',
			order_id: c.order,
			nonce: c.nonce,
		}, cfg.i18n.issue_ok, cfg.i18n.issue_fail, cfg.i18n.issuing );
	} );

	$( document ).on( 'click', '.mo-ecpay-invoice-invalid', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		if ( ! window.confirm( cfg.i18n.confirm_invalid ) ) {
			return;
		}
		const reason = window.prompt( cfg.i18n.invalid_prompt );
		if ( ! reason ) {
			return;
		}
		run( c, {
			action: 'mo_ecpay_invoice_invalid',
			order_id: c.order,
			reason: reason,
			nonce: c.nonce,
		}, cfg.i18n.invalid_ok, cfg.i18n.invalid_fail );
	} );

	$( document ).on( 'click', '.mo-ecpay-invoice-allowance', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		if ( ! window.confirm( cfg.i18n.confirm_allowance ) ) {
			return;
		}
		const amt = window.prompt( cfg.i18n.allowance_prompt );
		if ( ! amt ) {
			return;
		}
		run( c, {
			action: 'mo_ecpay_invoice_allowance',
			order_id: c.order,
			amount: amt,
			nonce: c.nonce,
		}, cfg.i18n.allowance_ok, cfg.i18n.allowance_fail );
	} );
} )( jQuery );
