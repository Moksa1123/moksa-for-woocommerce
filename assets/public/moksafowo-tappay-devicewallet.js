/**
 * TapPay 行動支付 — Classic checkout bridge（Apple Pay / Google Pay / Samsung Pay）。
 *
 * 跟電子錢包那支（moksafowo-tappay-wallet.js）差在兩點：
 *
 *   1. **可用性偵測**：裝置 / 瀏覽器不支援時，這個付款方式不該留在結帳頁 ——
 *      顧客選了才發現按不下去是最糟的。偵測結果回寫 WC session，
 *      PHP 端 is_available() 據此隱藏，然後觸發一次 update_checkout 讓列表刷新。
 *
 *   2. **各家 SDK 介面不同**：錢包都是 `getPrime(cb)` 一招；行動支付要先
 *      setup、再 setupPaymentRequest、有的還要自己渲染官方按鈕。
 *
 * 拿到 prime 後就跟信用卡一樣直接 pay-by-prime 成交，不導轉。
 */

( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoTappayDeviceSettings || {};
	var I18N = cfg.i18n || {};

	var sdkReady = false;
	var reported = {};   // gatewayId -> true，避免重複回報
	var prepared = {};   // gatewayId -> true，避免重複 setup

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

	/** 把偵測結果送回 server 存進 session，然後刷新結帳頁的付款方式列表。 */
	function report( gatewayId, supported ) {
		if ( reported[ gatewayId ] ) {
			return;
		}
		reported[ gatewayId ] = true;
		$.post( cfg.ajax_url, {
			action: 'moksafowo_tappay_device_support',
			nonce: cfg.nonce,
			gateway: gatewayId,
			supported: supported ? 1 : 0
		} ).always( function () {
			if ( ! supported ) {
				// 只有「不支援」需要刷新（要把這個方式藏掉）。
				$( document.body ).trigger( 'update_checkout' );
			}
		} );
	}

	function showError( $box, msg ) {
		$box.find( '.moksafowo-tappay-error' ).text( msg ).show();
	}

	// ---- 各家的 setup + getPrime ----------------------------------------

	function prepareApplePay( $box, gatewayId, onPrime ) {
		var api = window.TPDirect.paymentRequestApi;
		if ( ! api || ! api.checkAvailability() ) {
			report( gatewayId, false );
			return;
		}
		api.setupApplePay( {
			merchantIdentifier: cfg.applePayMerchantId || '',
			countryCode: 'TW'
		} );
		api.setupPaymentRequest( buildPaymentRequest(), function ( result ) {
			// 瀏覽器支援但沒有可用的卡，一樣不該顯示。
			var ok = !! ( result && result.browserSupportPaymentRequest && result.canMakePaymentWithActiveCard );
			report( gatewayId, ok );
			if ( ! ok ) {
				return;
			}
			renderButton( $box, 'apple', function () {
				api.getPrime( function ( r ) {
					if ( ! r || r.status !== 0 || ! r.prime ) {
						showError( $box, ( r && r.msg ) || I18N.prime_failed );
						return;
					}
					onPrime( r.prime );
				} );
			} );
		} );
	}

	function prepareGooglePay( $box, gatewayId, onPrime ) {
		var api = window.TPDirect.googlePay;
		if ( ! api ) {
			report( gatewayId, false );
			return;
		}
		api.setupGooglePay( {
			googleMerchantId: cfg.googleMerchantId || '',
			allowedCardAuthMethods: [ 'PAN_ONLY', 'CRYPTOGRAM_3DS' ],
			merchantName: cfg.googleMerchantName || ''
		} );
		api.setupPaymentRequest( {
			allowedNetworks: [ 'AMEX', 'JCB', 'MASTERCARD', 'VISA' ],
			price: String( cfg.amount || '0' ),
			currency: 'TWD'
		}, function ( err, result ) {
			var ok = ! err && !! ( result && result.canUseGooglePay );
			report( gatewayId, ok );
			if ( ! ok ) {
				return;
			}
			// Google 要求用官方按鈕，不能自己畫。
			api.setupGooglePayButton( {
				el: $box.find( '.moksafowo-tappay-devicewallet-button' ).get( 0 ),
				color: 'black',
				type: 'long',
				getPrimeCallback: function ( gErr, prime ) {
					if ( gErr || ! prime ) {
						showError( $box, I18N.prime_failed );
						return;
					}
					onPrime( prime );
				}
			} );
		} );
	}

	function prepareSamsungPay( $box, gatewayId, onPrime ) {
		var api = window.TPDirect.samsungPay;
		if ( ! api || typeof api.setup !== 'function' ) {
			report( gatewayId, false );
			return;
		}
		try {
			api.setup( { country_code: 'tw' } );
			api.setupPaymentRequest( buildPaymentRequest() );
		} catch ( e ) {
			report( gatewayId, false );
			return;
		}
		// Samsung Pay 的 web SDK 沒有 checkAvailability，官方做法是直接
		// 渲染按鈕；渲染不出來就當作不支援。
		var el = $box.find( '.moksafowo-tappay-devicewallet-button' ).get( 0 );
		try {
			api.setupSamsungPayButton( el, 'buy' );
		} catch ( e2 ) {
			report( gatewayId, false );
			return;
		}
		report( gatewayId, true );
		$( el ).on( 'click', function () {
			api.getPrime( function ( r ) {
				if ( ! r || r.status !== 0 || ! r.prime ) {
					showError( $box, ( r && r.msg ) || I18N.prime_failed );
					return;
				}
				onPrime( r.prime );
			} );
		} );
	}

	function buildPaymentRequest() {
		var amount = String( cfg.amount || '0' );
		return {
			supportedNetworks: [ 'MASTERCARD', 'VISA', 'JCB' ],
			supportedMethods: [ 'apple_pay' ],
			total: {
				label: cfg.merchantLabel || '',
				amount: { currency: 'TWD', value: amount }
			},
			options: {
				requestPayerEmail: false,
				requestPayerName: false,
				requestPayerPhone: false,
				requestShipping: false
			}
		};
	}

	/** Apple / Samsung 用我們自己的按鈕；Google 由 SDK 自己畫。 */
	function renderButton( $box, kind, onClick ) {
		var $slot = $box.find( '.moksafowo-tappay-devicewallet-button' );
		if ( $slot.children().length ) {
			return;
		}
		var $btn = $( '<button type="button" class="button moksafowo-tappay-device-btn"></button>' )
			.text( kind === 'apple' ? ( I18N.pay_apple || 'Apple Pay' ) : ( I18N.pay_samsung || 'Samsung Pay' ) );
		$btn.on( 'click', function ( e ) {
			e.preventDefault();
			onClick();
		} );
		$slot.append( $btn );
	}

	// ---- 掛載 -----------------------------------------------------------

	function mountAll() {
		if ( ! setupSdk() ) {
			return;
		}
		$( '.moksafowo-tappay-devicewallet' ).each( function () {
			var $box = $( this );
			var gatewayId = $box.data( 'moksafowo-tappay-gateway' );
			var ns = $box.data( 'moksafowo-tappay-sdk' );
			if ( ! gatewayId || prepared[ gatewayId ] ) {
				return;
			}
			prepared[ gatewayId ] = true;

			var onPrime = function ( prime ) {
				$box.find( '.moksafowo-tappay-prime' ).val( prime );
				$( 'form.checkout' ).trigger( 'submit' );
			};

			if ( ns === 'paymentRequestApi' ) {
				prepareApplePay( $box, gatewayId, onPrime );
			} else if ( ns === 'googlePay' ) {
				prepareGooglePay( $box, gatewayId, onPrime );
			} else if ( ns === 'samsungPay' ) {
				prepareSamsungPay( $box, gatewayId, onPrime );
			}
		} );
	}

	$( document.body ).on( 'updated_checkout payment_method_selected', function () {
		// 付款方式重畫後容器是新的，要重掛。
		prepared = {};
		mountAll();
	} );

	$( function () {
		mountAll();
	} );
} )( jQuery );
