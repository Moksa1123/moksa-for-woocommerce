/**
 * TapPay 電子錢包 — Classic checkout bridge（LINE Pay / 街口 / 悠遊付 / 一卡通 / 全支付）。
 *
 * 跟信用卡那支（moksafowo-tappay-fields.js）不同：錢包沒有要填的欄位，
 * 不需要 card.setup 掛 iframe，只要在送出訂單時呼叫該錢包的 getPrime()。
 *
 * TapPay 的錢包 SDK 介面高度統一 —— 都是 `TPDirect.<ns>.getPrime(cb)`，
 * 回 `{ status, msg, prime }`。所以這一支就能涵蓋五種，各 gateway 用
 * 容器上的 data-moksafowo-tappay-sdk 告訴我們該叫哪一個命名空間。
 *
 * 流程：
 *   1. setupSDK（跟信用卡共用同一組 appId / appKey / env）。
 *   2. checkout submit → 尚未有 prime 就 preventDefault，
 *      呼叫 TPDirect[ns].getPrime()，成功寫進 hidden input 後再放行。
 *   3. 後端 pay-by-prime 回 payment_url，WC 把顧客導去錢包完成付款。
 *
 * 只在選了 TapPay 錢包時才攔截 submit（不影響其他金流 — CLAUDE.md §3）。
 */

( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoTappayWalletSettings || {};
	var I18N = cfg.i18n || {};

	var sdkReady = false;
	var submitting = false;

	/** 目前勾選的付款方式若是我們的錢包，回傳它的容器；否則 null。 */
	function activeWallet() {
		var selected = $( 'input[name="payment_method"]:checked' ).val();
		if ( ! selected ) {
			return null;
		}
		var $box = $(
			'.moksafowo-tappay-wallet[data-moksafowo-tappay-gateway="' + selected + '"]'
		);
		return $box.length ? $box : null;
	}

	function setupSdk() {
		if ( sdkReady || ! window.TPDirect || ! cfg.appId || ! cfg.appKey ) {
			return sdkReady;
		}
		try {
			window.TPDirect.setupSDK(
				Number( cfg.appId ),
				String( cfg.appKey ),
				cfg.env === 'production' ? 'production' : 'sandbox'
			);
			sdkReady = true;
		} catch ( e ) {
			sdkReady = false;
		}
		return sdkReady;
	}

	function showError( $box, msg ) {
		$box.find( '.moksafowo-tappay-error' ).text( msg ).show();
	}

	function clearError( $box ) {
		$box.find( '.moksafowo-tappay-error' ).text( '' ).hide();
	}

	$( document.body ).on( 'updated_checkout payment_method_selected', function () {
		setupSdk();
	} );

	// 攔截「下單購買」：先 getPrime，拿到再放行。
	//
	// 綁在 form.checkout 本身的 checkout_place_order，不能用 delegation 攔原生
	// submit：WooCommerce 自己的 submit handler 直接綁在表單上、先跑，等事件冒泡到
	// document 時它已經帶著空的 prime 把訂單送出去了。而 WC 觸發 checkout_place_order
	// 用的是 triggerHandler（不冒泡），所以也不能掛在 document.body。
	// 用泛用的 checkout_place_order 一次涵蓋五個錢包，靠 activeWallet() 判斷是不是我們的。
	function onPlaceOrder() {
		var $box = activeWallet();
		if ( ! $box ) {
			return true; // 不是我們的錢包 —— 完全不介入。
		}

		var $prime = $box.find( '.moksafowo-tappay-prime' );
		if ( $prime.val() ) {
			return true; // 已經取到 prime，放行。
		}
		if ( submitting ) {
			return false;
		}

		if ( ! setupSdk() ) {
			showError( $box, I18N.sdk_failed || 'Payment service is unavailable. Please try again later.' );
			return false;
		}

		var ns = $box.data( 'moksafowo-tappay-sdk' );
		if ( ! ns || ! window.TPDirect[ ns ] || typeof window.TPDirect[ ns ].getPrime !== 'function' ) {
			// 該錢包在商家的 TapPay 帳號未開通時，SDK 不會掛上對應命名空間。
			showError( $box, I18N.not_enabled || 'This payment method is not available on this store.' );
			return false;
		}

		submitting = true;
		clearError( $box );
		var $form = $( 'form.checkout' );

		window.TPDirect[ ns ].getPrime( function ( result ) {
			submitting = false;
			if ( ! result || result.status !== 0 || ! result.prime ) {
				showError(
					$box,
					( result && result.msg ) || I18N.prime_failed || 'Could not start the payment. Please try again.'
				);
				return;
			}
			$prime.val( result.prime );
			$form.trigger( 'submit' );
		} );
		return false; // 先擋住，getPrime callback 內 re-submit。
	}

	function bindPlaceOrder() {
		$( 'form.checkout' )
			.off( 'checkout_place_order.moksafowoTappayWallet' )
			.on( 'checkout_place_order.moksafowoTappayWallet', onPlaceOrder );
	}

	$( document.body ).on( 'updated_checkout', bindPlaceOrder );
	$( bindPlaceOrder );
} )( jQuery );
