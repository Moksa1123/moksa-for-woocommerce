/**
 * 傳統結帳（幕前模式）：選統一物流超商取貨時藏起帳單地址欄，切回宅配再叫回來。
 *
 * 幕後模式由 store-selector.js 處理；幕前模式結帳頁不選門市、不載入那支，
 * 這支只負責「隱藏帳單地址欄」這個設定。必填在 PHP 端
 * （StoreSelector::modify_billing_fields_for_cvs）已經拿掉，這裡只管畫面與必填標記。
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	var PREFIX = 'moksafowo_payuni_shipping_711';
	var FIELDS = [
		'#billing_country_field',
		'#billing_postcode_field',
		'#billing_state_field',
		'#billing_city_field',
		'#billing_address_1_field',
		'#billing_address_2_field',
		'#billing_company_field'
	];

	function selectedMethod() {
		var $checked = $( 'input[name="shipping_method[0]"]:checked' );
		if ( $checked.length ) {
			return String( $checked.val() || '' );
		}
		// 只有一種運送方式時是 hidden input，沒有 :checked。
		var $only = $( 'input[name="shipping_method[0]"]' ).first();
		if ( $only.length ) {
			return String( $only.val() || '' );
		}
		var $select = $( 'select[name="shipping_method[0]"]' );
		return $select.length ? String( $select.val() || '' ) : '';
	}

	function hide() {
		FIELDS.forEach( function ( sel ) {
			var $field = $( sel );
			if ( ! $field.length ) {
				return;
			}
			if ( undefined === $field.data( 'moksafowo-payuni-original-required' ) ) {
				$field.data(
					'moksafowo-payuni-original-required',
					$field.hasClass( 'validate-required' ) || $field.hasClass( 'moksafowo-payuni-required-for-home' )
				);
			}
			$field.find( 'input, select' ).prop( 'required', false );
			$field.removeClass( 'validate-required woocommerce-invalid woocommerce-invalid-required-field' );
			$field.addClass( 'moksafowo-payuni-cvs-hide-billing' ).hide();
		} );
	}

	function show() {
		FIELDS.forEach( function ( sel ) {
			var $field = $( sel );
			if ( ! $field.length || ! $field.hasClass( 'moksafowo-payuni-cvs-hide-billing' ) ) {
				return;
			}
			if ( $field.data( 'moksafowo-payuni-original-required' ) || $field.hasClass( 'moksafowo-payuni-required-for-home' ) ) {
				$field.addClass( 'validate-required' );
				var $label = $field.find( 'label' ).first();
				$label.find( '.optional' ).remove();
				// WooCommerce 切換運送方式時自己會補 <span class="required">，已有就不要再加。
				if ( ! $label.find( '.required' ).length ) {
					$label.append( ' <span class="required" aria-hidden="true">*</span>' );
				}
				$label.find( '.required' ).show();
			}
			$field.removeClass( 'moksafowo-payuni-cvs-hide-billing' ).show();
		} );
	}

	function update() {
		if ( selectedMethod().split( ':' )[ 0 ].indexOf( PREFIX ) === 0 ) {
			hide();
		} else {
			show();
		}
	}

	$( function () {
		update();
		$( document.body ).on( 'updated_checkout', update );
		$( document ).on( 'change', 'input[name="shipping_method[0]"], select[name="shipping_method[0]"]', update );
	} );
}( window.jQuery ) );
