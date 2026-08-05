/* global jQuery, moksafowoCvsStoreEditor */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoCvsStoreEditor || {};
	var mapWindow = null;

	function picker() {
		return $( '.moksafowo-cvs-store-picker' ).first();
	}

	function orderId() {
		var $p = picker();
		if ( $p.length && $p.data( 'order-id' ) ) {
			return $p.data( 'order-id' );
		}
		return $( '[name="order_id"]' ).val() || $( '#post_ID' ).val() || 0;
	}

	function nonce() {
		var $field = picker().find( '[name="moksafowo_cvs_store_nonce"]' );
		return $field.length ? $field.val() : cfg.nonce;
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
		status( cfg.i18n ? cfg.i18n.opening : '' );
		mapWindow = window.open( '', 'moksafowo_cvs_store_map', 'width=1000,height=760' );
		if ( ! mapWindow ) {
			status( cfg.i18n ? cfg.i18n.blocked : '' );
			return;
		}

		$.post( cfg.ajaxUrl, {
			action: 'moksafowo_cvs_admin_open_map',
			nonce: nonce(),
			order_id: orderId(),
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

	// 超商取貨的收件地址就是門市，街道欄位填了也不會送出去，藏起來免得商家誤以為要填。
	// 換回非超商的運送方式時要還原，所以走 toggle 不是單向 hide。
	var STREET_FIELDS = [
		'_shipping_company_field',
		'_shipping_address_1_field',
		'_shipping_address_2_field',
		'_shipping_city_field',
		'_shipping_postcode_field',
		'_shipping_country_field',
		'_shipping_state_field',
	];

	function syncStreetFields() {
		var isCvs = picker().length > 0;
		STREET_FIELDS.forEach( function ( cls ) {
			$( '.' + cls ).css( 'display', isCvs ? 'none' : '' );
		} );
	}

	function shippingColumn() {
		return $( '#order_data .order_data_column' ).eq( 2 );
	}

	// 訂單資料面板是伺服器端畫的，品項面板改了運送方式不會連帶重畫。
	// 這裡在品項存檔後跟伺服器要一次「現在該有的門市欄位」就地換掉，
	// 商家不必先存訂單再重新整理才看得到欄位。
	function refreshFields() {
		var id = orderId();
		if ( ! id ) {
			return;
		}
		$.post( cfg.ajaxUrl, {
			action: 'moksafowo_cvs_admin_fields',
			nonce: nonce(),
			order_id: id,
		} ).done( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				return;
			}
			var current = picker().data( 'provider' ) || '';
			if ( current === ( res.data.provider || '' ) ) {
				return;
			}

			$( '.moksafowo-cvs-store-picker' ).remove();
			shippingColumn().find( '.moksafowo-cvs-store-field' ).closest( 'p.form-field' ).remove();

			if ( res.data.provider ) {
				var $edit = shippingColumn().find( 'div.edit_address' ).first();
				$edit.append( res.data.fields );
				$edit.after( res.data.picker );
			}
			// 換回非超商時要把街道欄位放回來，所以兩條路徑都得跑
			syncStreetFields();
		} );
	}

	$( function () {
		syncStreetFields();
		$( document ).on( 'click', 'a.edit_address', syncStreetFields );
		$( document ).on( 'click', '.moksafowo-cvs-store-open-map', function ( e ) {
			e.preventDefault();
			openMap();
		} );

		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			var data = settings && settings.data ? String( settings.data ) : '';
			if (
				data.indexOf( 'action=woocommerce_save_order_items' ) !== -1 ||
				data.indexOf( 'action=woocommerce_remove_order_item' ) !== -1 ||
				data.indexOf( 'action=woocommerce_calc_line_taxes' ) !== -1
			) {
				refreshFields();
			}
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
