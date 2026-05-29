/**
 * TapPay 拍付 — Classic checkout（非 Block）TapPay Fields bridge。
 *
 * Module::enqueue_checkout_assets() 在傳統結帳頁載 tpdirect SDK +
 * 這支 + moTappaySettings（appId / appKey / env / gatewayId / i18n）。
 *
 * 流程：
 *   1. SDK setupSDK + card.setup 把 iframe 卡號欄位 render 進
 *      payment_fields() 吐的 .mo-tappay-fields 容器（#mo-tappay-card-*）。
 *   2. card.onUpdate → 記 canGetPrime + gate #place_order（不能取 prime 時 disable）。
 *   3. checkout submit（jQuery checkout_place_order_<id>）：
 *      尚未有 prime → preventDefault，getPrime() 成功後把 prime / bin /
 *      last4 / issuer 寫進 hidden input，再 .submit() 放行。
 *
 * 只在選了 TapPay gateway 時才攔截 submit（不影響其他金流 — CLAUDE.md §3）。
 * Block checkout 不靠這支（Block JS 自走 SDK）。
 */

( function ( $ ) {
	'use strict';

	var cfg = window.moTappaySettings || {};
	var GATEWAY = cfg.gatewayId || 'mo_tappay_credit';
	var I18N = cfg.i18n || {};

	var sdkReady = false;
	var fieldsMounted = false;
	var canGetPrime = false;
	var submitting = false;

	function $container() {
		return $( '.mo-tappay-fields[data-mo-tappay-gateway="' + GATEWAY + '"]' );
	}

	function selectedGateway() {
		var checked = $(
			'input[name="payment_method"]:checked'
		).val();
		return checked === GATEWAY;
	}

	function setError( msg ) {
		var $err = $container().find( '.mo-tappay-error' );
		if ( ! $err.length ) {
			return;
		}
		if ( msg ) {
			$err.text( msg ).show();
		} else {
			$err.text( '' ).hide();
		}
	}

	function ensureSdk() {
		if ( sdkReady ) {
			return true;
		}
		if ( typeof window.TPDirect === 'undefined' ) {
			return false;
		}
		try {
			window.TPDirect.setupSDK(
				parseInt( cfg.appId, 10 ) || 0,
				cfg.appKey || '',
				cfg.env === 'production' ? 'production' : 'sandbox'
			);
			sdkReady = true;
		} catch ( e ) {
			sdkReady = true; // 已 setup 過 → 視為就緒。
		}
		return true;
	}

	function mountFields() {
		if ( fieldsMounted ) {
			return;
		}
		var $c = $container();
		if (
			! $c.length ||
			! $c.find( '#mo-tappay-card-number' ).length ||
			! ensureSdk()
		) {
			return;
		}
		fieldsMounted = true;

		try {
			window.TPDirect.card.setup( {
				fields: {
					number: {
						element: '#mo-tappay-card-number',
						placeholder: '**** **** **** ****',
					},
					expirationDate: {
						element: '#mo-tappay-card-expiry',
						placeholder: 'MM / YY',
					},
					ccv: {
						element: '#mo-tappay-card-ccv',
						placeholder: 'CCV',
					},
				},
				styles: {
					input: {
						color: '#1e1e1e',
						'font-size': '16px',
						'line-height': '1.4',
					},
					'.valid': { color: '#1a7f37' },
					'.invalid': { color: '#b32d2e' },
				},
				isMaskCreditCardNumber: true,
				maskCreditCardNumberRange: {
					beginIndex: 6,
					endIndex: 11,
				},
			} );

			window.TPDirect.card.onUpdate( function ( update ) {
				canGetPrime = !! update.canGetPrime;
				if ( canGetPrime ) {
					setError( '' );
				}
				togglePlaceOrder();
			} );
		} catch ( e ) {
			fieldsMounted = false;
		}
	}

	function togglePlaceOrder() {
		if ( ! selectedGateway() ) {
			$( '#place_order' ).prop( 'disabled', false );
			return;
		}
		$( '#place_order' ).prop( 'disabled', ! canGetPrime );
	}

	function injectPrimeAndSubmit( $form ) {
		window.TPDirect.card.getPrime( function ( result ) {
			submitting = false;
			if ( result.status !== 0 ) {
				setError(
					result.msg ||
						I18N.primeError ||
						'無法取得付款憑證，請確認卡號。'
				);
				$( '#place_order' ).prop( 'disabled', false );
				return;
			}
			var card = result.card || {};
			var $c = $container();
			$c.find( '.mo-tappay-prime' ).val( card.prime || '' );
			$c.find( '.mo-tappay-bin' ).val( card.bin_code || '' );
			$c.find( '.mo-tappay-last-four' ).val(
				card.last_four || ''
			);
			$c.find( '.mo-tappay-issuer' ).val( card.issuer || '' );
			// prime 已寫入 → 放行原本的 checkout submit。
			$form.trigger( 'submit' );
		} );
	}

	// 攔截傳統結帳 submit：先 getPrime，拿到再放行。
	$( document.body ).on(
		'checkout_place_order_' + GATEWAY,
		function () {
			if ( ! selectedGateway() ) {
				return true;
			}
			var $c = $container();
			var existing = $c.find( '.mo-tappay-prime' ).val();
			if ( existing ) {
				return true; // 已有 prime（getPrime 後 re-submit）→ 放行。
			}
			if ( submitting ) {
				return false;
			}
			if ( ! window.TPDirect || ! window.TPDirect.card ) {
				setError(
					I18N.primeError || 'TapPay SDK 尚未就緒。'
				);
				return false;
			}
			var status =
				window.TPDirect.card.getTappayFieldsStatus();
			if ( status && status.canGetPrime === false ) {
				setError(
					I18N.incomplete || '請完整填寫信用卡資訊。'
				);
				return false;
			}
			submitting = true;
			setError( '' );
			injectPrimeAndSubmit( $( 'form.checkout' ) );
			return false; // 先擋住，getPrime callback 內 re-submit。
		}
	);

	// updated_checkout / payment_method 變更後重新 mount + gate。
	$( document.body ).on( 'updated_checkout', function () {
		mountFields();
		togglePlaceOrder();
	} );
	$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
		// 切回 TapPay 時把舊 prime 清掉（避免用過期 prime 送單）。
		$container().find( '.mo-tappay-prime' ).val( '' );
		mountFields();
		togglePlaceOrder();
	} );

	$( function () {
		mountFields();
		togglePlaceOrder();
	} );
} )( jQuery );
