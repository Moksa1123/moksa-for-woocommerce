/* 訂單頁退款：加「訂單編號末 4 碼」欄位，退款請求帶上末 4 碼與一次性 token；每次送完換新 token。 */
( function ( $, cfg ) {
	'use strict';
	if ( ! cfg ) {
		return;
	}
	var token = cfg.token;

	function addField() {
		var $actions = $( '.wc-order-refund-items .refund-actions' );
		if ( ! $actions.length || $( '#moksafowo-refund-confirm' ).length ) {
			return;
		}
		var $p = $( '<p class="moksafowo-refund-confirm" style="text-align:right;margin:8px 0"></p>' );
		if ( cfg.unknown ) {
			$p.append( $( '<strong style="display:block;color:#b32d2e;margin-bottom:6px"></strong>' ).text( cfg.unknown ) );
		}
		var $label = $( '<label for="moksafowo-refund-confirm"></label>' ).text( cfg.label + ' ' );
		var $input = $( '<input type="text" id="moksafowo-refund-confirm" autocomplete="off" maxlength="8" style="width:90px">' );
		$p.append( $label, $input );
		$actions.before( $p );
	}

	$( document.body ).on( 'click', 'button.refund-items', function () {
		setTimeout( addField, 0 );
	} );
	$( addField );

	$.ajaxPrefilter( function ( options ) {
		if ( 'string' !== typeof options.data || -1 === options.data.indexOf( 'action=woocommerce_refund_line_items' ) ) {
			return;
		}
		options.data += '&moksafowo_refund_confirm=' + encodeURIComponent( $( '#moksafowo-refund-confirm' ).val() || '' ) +
			'&moksafowo_refund_token=' + encodeURIComponent( token );
		var done = options.complete;
		options.complete = function () {
			// token 只能用一次：不論成功失敗都換新的，失敗後可以直接再按。
			$.post( window.ajaxurl, { action: 'moksafowo_refund_token', nonce: cfg.nonce, order_id: cfg.orderId }, function ( r ) {
				if ( r && r.success ) {
					token = r.data.token;
				}
			} );
			if ( done ) {
				done.apply( this, arguments );
			}
		};
	} );
} )( jQuery, window.moksafowoRefundGuard );
