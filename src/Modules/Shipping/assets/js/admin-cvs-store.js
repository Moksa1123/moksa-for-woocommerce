/* global jQuery, moksafowoCvsStoreEditor */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoCvsStoreEditor || {};
	var mapWindow = null;

	function picker() {
		return $( '.moksafowo-cvs-store-picker' ).first();
	}

	function status( text ) {
		picker().find( '.moksafowo-cvs-store-status' ).text( text || '' );
	}

	function fill( store ) {
		var $p = picker();
		if ( ! $p.length ) {
			return;
		}
		var map = {
			'store-id-field': store.id,
			'store-name-field': store.name,
			'store-address-field': store.address,
		};
		Object.keys( map ).forEach( function ( key ) {
			var name = $p.data( key );
			if ( ! name ) {
				return;
			}
			var $input = $( '#' + name.replace( /([^\w-])/g, '\\$1' ) );
			if ( ! $input.length ) {
				$input = $( '[name="' + name + '"]' );
			}
			if ( $input.length ) {
				$input.val( map[ key ] || '' ).trigger( 'change' );
			}
		} );
		status( cfg.i18n ? cfg.i18n.chosen : '' );
	}

	// 地圖開在新視窗，訂單頁沒存的編輯才不會被沖掉；回程由 render_return() postMessage 回來。
	function openMap() {
		var $p = picker();
		var nonce = $p.find( '[name="moksafowo_cvs_store_nonce"]' ).val();

		status( cfg.i18n ? cfg.i18n.opening : '' );
		mapWindow = window.open( '', 'moksafowo_cvs_store_map', 'width=1000,height=760' );
		if ( ! mapWindow ) {
			status( cfg.i18n ? cfg.i18n.blocked : '' );
			return;
		}

		$.post( cfg.ajaxUrl, {
			action: 'moksafowo_cvs_admin_open_map',
			nonce: nonce,
			order_id: $p.data( 'order-id' ),
		} )
			.done( function ( res ) {
				if ( ! res || ! res.success || ! res.data ) {
					mapWindow.close();
					status( ( res && res.data && res.data.message ) || ( cfg.i18n ? cfg.i18n.failed : '' ) );
					return;
				}
				if ( res.data.url ) {
					mapWindow.location.href = res.data.url;
					status( '' );
					return;
				}
				var $form = $( '<form>', {
					method: 'post',
					action: res.data.api_url,
					target: 'moksafowo_cvs_store_map',
				} );
				$.each( res.data.form_data || {}, function ( key, value ) {
					$form.append(
						$( '<input>', { type: 'hidden', name: key, value: value } )
					);
				} );
				$( 'body' ).append( $form );
				$form.trigger( 'submit' );
				$form.remove();
				status( '' );
			} )
			.fail( function () {
				if ( mapWindow ) {
					mapWindow.close();
				}
				status( cfg.i18n ? cfg.i18n.failed : '' );
			} );
	}

	// 超商取貨的收件地址就是門市，街道欄位填了也不會送出去，藏起來免得商家誤以為要填
	function hideStreetFields() {
		[
			'_shipping_company_field',
			'_shipping_address_1_field',
			'_shipping_address_2_field',
			'_shipping_city_field',
			'_shipping_postcode_field',
			'_shipping_country_field',
			'_shipping_state_field',
		].forEach( function ( cls ) {
			$( '.' + cls ).hide();
		} );
	}

	$( function () {
		if ( ! picker().length ) {
			return;
		}
		hideStreetFields();
		$( document ).on( 'click', 'a.edit_address', hideStreetFields );
		$( document ).on( 'click', '.moksafowo-cvs-store-open-map', function ( e ) {
			e.preventDefault();
			openMap();
		} );
	} );

	window.addEventListener( 'message', function ( event ) {
		if ( cfg.origin && event.origin !== cfg.origin ) {
			return;
		}
		var data = event.data;
		if ( ! data || data.type !== cfg.messageType ) {
			return;
		}
		fill( data );
	} );
} )( jQuery );
