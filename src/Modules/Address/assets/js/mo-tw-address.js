/**
 * mowp 台灣地址下拉 — Classic checkout 動態 city dropdown。
 *
 * 流程：
 *   1. 切換 country → state，state_select / state_changing 事件
 *   2. 看 mo_tw_address.cities[country][state] 有沒有對應的 cities 陣列
 *   3. 有 → 把 #*_city 從 input 改成 select + 填 options
 *      沒 → 改回 input
 *   4. 選 city → 從 selected option 的 data-postcode 帶入 postcode
 *
 * Logic 對齊 RY-WC-City-Select 的 ry-city-select.js (GPLv3)。
 */
( function ( $ ) {
	'use strict';

	if ( typeof wc_country_select_params === 'undefined' || typeof mo_tw_address === 'undefined' ) {
		return;
	}

	$( function () {
		// SelectWoo 美化 city dropdown（如果可用）
		if ( $.fn.selectWoo ) {
			const select_args = function () {
				return {
					placeholder: $( this ).attr( 'data-placeholder' ) || $( this ).attr( 'placeholder' ) || '',
					width: '100%',
				};
			};
			const apply_select_woo = function () {
				$( 'select.city_select:visible' ).each( function () {
					$( this ).on( 'select2:select', function () {
						$( this ).trigger( 'focus' );
					} ).selectWoo( select_args.call( this ) );
				} );
			};
			apply_select_woo();
			$( document.body ).on( 'city_to_select', apply_select_woo );
		}

		// Country 改變時 — bridge 到 state_changing 事件
		$( document.body ).on( 'country_to_state_changing', function ( e, country, $form ) {
			const state = $form.find( '#billing_state, #shipping_state, #calc_shipping_state' ).val();
			$( document.body ).trigger( 'state_changing', [ country, state, $form ] );
		} );

		// State 改變時 — fire state_changing
		$( document.body ).on( 'change', 'select.state_select, #calc_shipping_state', function () {
			const $form = $( this ).closest( '.form-row' ).parent();
			const country = $form.find( '#billing_country, #shipping_country, #calc_shipping_country' ).val();
			const state = $( this ).val();
			$( document.body ).trigger( 'state_changing', [ country, state, $form ] );
		} );

		// 主邏輯：依 country/state 決定 city 顯示 dropdown 還是 input
		$( document.body ).on( 'state_changing', function ( e, country, state, $form ) {
			const $city = $form.find( '#billing_city, #shipping_city, #calc_shipping_city' );

			if ( mo_tw_address.cities[ country ] && state && mo_tw_address.cities[ country ][ state ] ) {
				render_city_dropdown( $city, mo_tw_address.cities[ country ][ state ] );
			} else {
				render_city_input( $city );
			}
		} );

		// 選 city → 自動帶入 postcode
		$( document.body ).on( 'change', 'select.city_select', function () {
			const $form = $( this ).closest( '.form-row' ).parent();
			const postcode = $form.find( '#billing_city, #shipping_city, #calc_shipping_city' ).find( ':selected' ).data( 'postcode' );
			const $postcode = $form.find( '#billing_postcode, #shipping_postcode, #calc_shipping_postcode' );
			$postcode.val( postcode !== undefined ? postcode : '' );
		} );

		// 購物車運費試算頁的 state mutation observer（RY 同樣處理）
		if ( $( '.cart-collaterals' ).length && $( '#calc_shipping_state' ).length ) {
			new MutationObserver( function () {
				$( '#calc_shipping_state' ).trigger( 'change' );
			} ).observe( document.querySelector( '.cart-collaterals' ), { childList: true } );
		}
	} );

	function render_city_dropdown( $city, cities ) {
		const value = $city.val();

		// input → select
		if ( $city.is( 'input' ) ) {
			const name = $city.attr( 'name' );
			const id = $city.attr( 'id' );
			const placeholder = $city.attr( 'placeholder' );
			const $select = $( '<select></select>' )
				.prop( 'id', id )
				.prop( 'name', name )
				.data( 'placeholder', placeholder )
				.addClass( 'city_select' );
			$city.replaceWith( $select );
			$city = $( '#' + id );
		} else {
			$city.prop( 'disabled', false );
		}

		$city.empty();
		for ( const k in cities ) {
			if ( ! Object.prototype.hasOwnProperty.call( cities, k ) ) {
				continue;
			}
			const city = cities[ k ];
			const $opt = $( '<option></option>' );
			let label;
			if ( city instanceof Array ) {
				label = city[0];
				$opt.attr( 'data-postcode', city[1] );
			} else {
				label = city;
			}
			$opt.prop( 'value', label ).text( label );
			$city.append( $opt );
		}

		// 還原既有選擇
		const $existing = $city.find( 'option[value="' + value + '"]' );
		if ( $existing.length ) {
			$existing.prop( 'selected', true );
		} else {
			$city.find( 'option:first' ).prop( 'selected', true );
		}
		$city.trigger( 'change' );
		$( document.body ).trigger( 'city_to_select' );
	}

	function render_city_input( $city ) {
		if ( $city.is( 'input' ) ) {
			$city.prop( 'disabled', false );
			return;
		}
		const name = $city.attr( 'name' );
		const id = $city.attr( 'id' );
		const placeholder = $city.attr( 'placeholder' );
		const $input = $( '<input type="text">' )
			.prop( 'id', id )
			.prop( 'name', name )
			.prop( 'placeholder', placeholder )
			.addClass( 'input-text' );
		$city.parent().find( '.select2-container' ).remove();
		$city.replaceWith( $input );
		$( '#' + id )
			.closest( '.form-row' )
			.parent()
			.find( '#billing_postcode, #shipping_postcode, #calc_shipping_postcode' )
			.val( '' );
	}
} )( jQuery );
