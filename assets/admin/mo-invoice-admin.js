/**
 * mowp 共用發票 admin metabox JS — 處理 Issue / Invalid / Allowance 按鈕。
 *
 * 各 provider 透過 AbstractAdminMetaBox 渲染 div.mo-invoice-meta with
 * data-provider="amego" + data-prefix="mo_amego_invoice" + nonce field。
 * 本 JS 從 data attr 讀 provider key 動態建 AJAX action。
 */
( function ( $ ) {
	'use strict';
	if ( ! window.mo_invoice_admin_i18n ) {
		return;
	}
	const cfg = window.mo_invoice_admin_i18n;

	function ctx( $btn ) {
		const $box = $btn.closest( '.mo-invoice-meta' );
		const prefix = $box.data( 'prefix' );
		return {
			$btn:   $btn,
			$box:   $box,
			order:  $box.data( 'order-id' ),
			prefix: prefix,
			nonce:  $box.find( 'input[name="' + prefix + '_nonce"]' ).val(),
		};
	}

	function fail( prefix, resp ) {
		return prefix + ( ( resp && resp.data && resp.data.message ) || cfg.unknown_error );
	}

	function run( c, payload, okMsg, failPrefix, busyText ) {
		c.$btn.prop( 'disabled', true );
		if ( busyText ) {
			c.$btn.data( 'orig', c.$btn.text() ).text( busyText );
		}
		$.post( cfg.ajax_url, payload ).done( function ( resp ) {
			if ( resp && resp.success ) {
				window.alert( okMsg );
				window.location.reload();
			} else {
				window.alert( fail( failPrefix, resp ) );
				c.$btn.prop( 'disabled', false );
				if ( busyText ) {
					c.$btn.text( c.$btn.data( 'orig' ) || '' );
				}
			}
		} ).fail( function () {
			window.alert( fail( failPrefix, null ) );
			c.$btn.prop( 'disabled', false );
			if ( busyText ) {
				c.$btn.text( c.$btn.data( 'orig' ) || '' );
			}
		} );
	}

	$( document ).on( 'click', '.mo-invoice-meta .mo-invoice-issue', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		run( c, {
			action:   c.prefix + '_issue',
			order_id: c.order,
			nonce:    c.nonce,
		}, cfg.issue_ok, cfg.issue_fail, cfg.issuing );
	} );

	$( document ).on( 'click', '.mo-invoice-meta .mo-invoice-invalid', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		if ( ! window.confirm( cfg.confirm_invalid ) ) {
			return;
		}
		const reason = window.prompt( cfg.invalid_prompt );
		if ( ! reason ) {
			return;
		}
		run( c, {
			action:   c.prefix + '_invalid',
			order_id: c.order,
			reason:   reason,
			nonce:    c.nonce,
		}, cfg.invalid_ok, cfg.invalid_fail );
	} );

	$( document ).on( 'click', '.mo-invoice-meta .mo-invoice-allowance', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		if ( ! window.confirm( cfg.confirm_allowance ) ) {
			return;
		}
		const amt = window.prompt( cfg.allowance_prompt );
		if ( ! amt ) {
			return;
		}
		run( c, {
			action:   c.prefix + '_allowance',
			order_id: c.order,
			amount:   amt,
			nonce:    c.nonce,
		}, cfg.allowance_ok, cfg.allowance_fail );
	} );
} )( jQuery );
