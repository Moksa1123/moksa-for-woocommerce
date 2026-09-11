/**
 * 傳統結帳的半寬配對 —— 依「實際看得到的欄位」重算。
 *
 * PHP（FieldManager::apply_layout）在渲染前依商家的排序給每格 form-row-first / last / wide，
 * 但欄位會被各種機制事後藏掉：隱藏國家（CSS）、超商取貨隱藏帳單地址（切運送方式後）、
 * WooCommerce 自己的公司 / 地址二欄位設定……PHP 不可能全知道。被藏的欄位仍佔配對位置時，
 * 它的搭檔會拿到 first 卻沒有 last，半寬卡在左邊落單。
 *
 * 所以每次 WC 刷新後在這裡重算一次：只看可見、且帶 moksafowo-w-50 / w-100 的列，
 * 相鄰兩個 50 才並排，其餘一律撐滿。
 */
( function ( $ ) {
	'use strict';

	var WRAPPERS =
		'.woocommerce-billing-fields__field-wrapper,' +
		'.woocommerce-shipping-fields__field-wrapper,' +
		'.woocommerce-address-fields__field-wrapper';

	function widthOf( $row ) {
		if ( $row.hasClass( 'moksafowo-w-50' ) ) {
			return 50;
		}
		if ( $row.hasClass( 'moksafowo-w-100' ) ) {
			return 100;
		}
		return null; // 不是我們管的欄位（例如其他外掛加的），當作配對邊界。
	}

	function repair( $wrapper ) {
		var rows = $wrapper.children( '.form-row' ).filter( function () {
			return $( this ).is( ':visible' );
		} ).toArray();
		var i = 0;
		while ( i < rows.length ) {
			var $cur = $( rows[ i ] );
			var w = widthOf( $cur );
			if ( null === w ) {
				i++;
				continue;
			}
			var $next = rows[ i + 1 ] ? $( rows[ i + 1 ] ) : null;
			var nw = $next ? widthOf( $next ) : null;
			$cur.removeClass( 'form-row-first form-row-last form-row-wide' );
			if ( 50 === w && 50 === nw ) {
				$cur.addClass( 'form-row-first' );
				$next.removeClass( 'form-row-first form-row-last form-row-wide' ).addClass( 'form-row-last' );
				i += 2;
				continue;
			}
			$cur.addClass( 'form-row-wide' );
			i++;
		}
	}

	function run() {
		$( WRAPPERS ).each( function () {
			repair( $( this ) );
		} );
	}

	$( run );
	// 其他模組（超商取貨隱藏帳單）也掛在 updated_checkout 上藏欄位，排到它們之後再算。
	$( document.body ).on( 'updated_checkout', function () {
		setTimeout( run, 0 );
	} );
	$( document.body ).on( 'change', 'input[name^="shipping_method"], input[name="payment_method"], #ship-to-different-address-checkbox', function () {
		setTimeout( run, 50 );
	} );
} )( jQuery );
