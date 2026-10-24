/**
 * Shopline Payments 內嵌式信用卡（傳統結帳）。
 *
 *   1. 選到這個付款方式時把 SLP SDK 收銀台掛進 #moksafowo-slp-card-container。
 *   2. 送出時先 payment.createPayment() 取 paySession，寫進隱藏欄位再放行。
 *   3. 伺服器建立交易後回傳 nextAction，在 checkout_place_order_success 攔下 WC 的跳轉，
 *      交給 payment.pay()；SDK 會完成 3D 驗證並導到訂單完成頁。
 *
 * 兩個 WC 事件都是對 form.checkout 呼叫 triggerHandler（不冒泡），一定要綁在表單本身。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoSlpCard || {};
	var GATEWAY = cfg.gatewayId || 'moksafowo_shopline_card';
	var I18N = cfg.i18n || {};

	var payment = null;
	var mountedOn = null;
	var initing = false;
	var submitting = false;

	function selected() {
		return $( 'input[name="payment_method"]:checked' ).val() === GATEWAY;
	}

	function $box() {
		return $( '.moksafowo-slp-card[data-moksafowo-slp-gateway="' + GATEWAY + '"]' );
	}

	function setError( msg ) {
		var $err = $box().find( '.moksafowo-slp-error' );
		if ( msg ) {
			$err.text( msg ).show();
		} else {
			$err.text( '' ).hide();
		}
	}

	// 以結帳頁顯示的訂單總額為準（切運送方式後 cfg.amount 會過時）；SDK 金額單位是元 × 100。
	function amount() {
		var text = $( '.order-total .woocommerce-Price-amount' ).last().text();
		var value = parseFloat( String( text ).replace( /[^0-9.]/g, '' ) );
		if ( ! isNaN( value ) && value > 0 ) {
			return Math.round( value * 100 );
		}
		return Math.max( parseInt( cfg.amount, 10 ) || 0, 100 );
	}

	function mount() {
		var el = document.getElementById( 'moksafowo-slp-card-container' );
		if ( ! el || ! window.ShoplinePayments || initing ) {
			return;
		}
		// update_order_review 會把付款區整塊換掉，舊實例掛在已移除的節點上，要重建。
		if ( payment && mountedOn === el && el.childElementCount > 0 ) {
			return;
		}
		if ( payment ) {
			try {
				payment.destroy();
			} catch ( e ) {}
			payment = null;
		}

		var opts = {
			clientKey: cfg.clientKey,
			merchantId: cfg.merchantId,
			paymentMethod: 'CreditCard',
			currency: 'TWD',
			amount: Math.max( amount(), 100 ),
			element: '#moksafowo-slp-card-container',
			env: cfg.env === 'production' ? 'production' : 'sandbox',
		};
		if ( cfg.bindCard ) {
			// 訂閱一定要綁卡才能續扣：不顯示勾選框，並列出定期代扣協議。
			opts.paymentInstrument = {
				bindCard: {
					enable: true,
					protocol: {
						switchVisible: false,
						textType: { paymentAgreement: true, subscribeAgreement: true },
					},
				},
			};
		}

		initing = true;
		window
			.ShoplinePayments( opts )
			.then( function ( res ) {
				initing = false;
				if ( ! res || res.error ) {
					setError( I18N.loadError );
					return;
				}
				payment = res.payment;
				mountedOn = el;
				setError( '' );
			} )
			.catch( function () {
				initing = false;
				setError( I18N.loadError );
			} );
	}

	function onPlaceOrder() {
		if ( ! selected() ) {
			return true;
		}
		var $session = $box().find( '.moksafowo-slp-pay-session' );
		if ( $session.val() ) {
			return true;
		}
		if ( submitting ) {
			return false;
		}
		if ( ! payment ) {
			setError( I18N.loadError );
			return false;
		}
		submitting = true;
		setError( '' );
		payment
			.createPayment()
			.then( function ( res ) {
				submitting = false;
				// 卡號有誤時 SDK 會在欄位下方提示，這裡不用另外顯示。
				if ( ! res || res.error || ! res.paySession ) {
					return;
				}
				var session = typeof res.paySession === 'string' ? res.paySession : JSON.stringify( res.paySession );
				$session.val( session );
				$( 'form.checkout' ).trigger( 'submit' );
			} )
			.catch( function () {
				submitting = false;
				setError( I18N.payError );
			} );
		return false;
	}

	function onPlaceOrderSuccess( e, result ) {
		// paySession 只能用一次，不論結果都清掉，重試時重新取得。
		$box().find( '.moksafowo-slp-pay-session' ).val( '' );
		if ( ! selected() || ! result || ! result.moksafowo_slp_next_action || ! payment ) {
			return true;
		}
		var next = result.moksafowo_slp_next_action;
		try {
			next = JSON.parse( next );
		} catch ( err ) {}

		payment
			.pay( next )
			.then( function ( res ) {
				if ( res && res.error ) {
					$( 'form.checkout' ).removeClass( 'processing' ).unblock();
					setError( I18N.payError );
					return;
				}
				// 正常情況 SDK 自己會導到 returnUrl；沒導走就手動去訂單完成頁。
				if ( result.redirect ) {
					window.location = result.redirect;
				}
			} )
			.catch( function () {
				$( 'form.checkout' ).removeClass( 'processing' ).unblock();
				setError( I18N.payError );
			} );
		return false;
	}

	function bind() {
		var $form = $( 'form.checkout' );
		$form
			.off( 'checkout_place_order_' + GATEWAY + '.moksafowoSlp' )
			.on( 'checkout_place_order_' + GATEWAY + '.moksafowoSlp', onPlaceOrder );
		$form
			.off( 'checkout_place_order_success.moksafowoSlp' )
			.on( 'checkout_place_order_success.moksafowoSlp', onPlaceOrderSuccess );
	}

	$( document.body ).on( 'updated_checkout', function () {
		bind();
		if ( selected() ) {
			mount();
		}
	} );
	$( document.body ).on( 'change', 'input[name="payment_method"]', function () {
		if ( selected() ) {
			mount();
		}
	} );
	$( function () {
		bind();
		if ( selected() ) {
			mount();
		}
	} );
} )( jQuery );
