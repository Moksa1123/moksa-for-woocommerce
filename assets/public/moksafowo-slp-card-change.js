/**
 * Shopline Payments 顧客自行換卡（我的帳號 → 訂閱 → 查看）。
 *
 *   1. 把 SLP SDK 卡號欄位掛進 #moksafowo-slp-card-change-container（只綁卡，金額 1 元驗卡）。
 *   2. 按下按鈕 → payment.createPayment() 取 paySession → 送到 /wc-api/moksafowo_slp_card_change
 *      建立 1 元驗卡綁卡交易，拿回 nextAction。
 *   3. payment.pay( nextAction ) 完成 3D 驗證後 SDK 導回訂閱頁；結果以 webhook 為準。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoSlpCardChange || {};
	var I18N = cfg.i18n || {};
	var payment = null;
	var busy = false;

	function setError( msg ) {
		var $err = $( '.moksafowo-slp-card-change .moksafowo-slp-error' );
		if ( msg ) {
			$err.text( msg ).show();
		} else {
			$err.text( '' ).hide();
		}
	}

	function setBusy( state ) {
		busy = state;
		$( '.moksafowo-slp-card-change-submit' ).prop( 'disabled', state );
	}

	function mount() {
		if ( ! window.ShoplinePayments || ! document.getElementById( 'moksafowo-slp-card-change-container' ) ) {
			setError( I18N.loadError );
			return;
		}
		window
			.ShoplinePayments( {
				clientKey: cfg.clientKey,
				merchantId: cfg.merchantId,
				paymentMethod: 'CreditCard',
				currency: 'TWD',
				amount: 100,
				element: '#moksafowo-slp-card-change-container',
				env: cfg.env === 'production' ? 'production' : 'sandbox',
				paymentInstrument: {
					bindCard: {
						enable: true,
						protocol: {
							switchVisible: false,
							textType: { paymentAgreement: true, subscribeAgreement: true },
						},
					},
				},
			} )
			.then( function ( res ) {
				if ( ! res || res.error ) {
					setError( I18N.loadError );
					return;
				}
				payment = res.payment;
			} )
			.catch( function () {
				setError( I18N.loadError );
			} );
	}

	function fail( msg ) {
		setBusy( false );
		setError( msg || I18N.payError );
	}

	function submit() {
		if ( busy || ! payment ) {
			return;
		}
		setError( '' );
		setBusy( true );
		payment
			.createPayment()
			.then( function ( res ) {
				// 卡號有誤時 SDK 會在欄位下方提示。
				if ( ! res || res.error || ! res.paySession ) {
					setBusy( false );
					return;
				}
				var session = typeof res.paySession === 'string' ? res.paySession : JSON.stringify( res.paySession );
				$.post( cfg.startUrl, {
					subscription_id: cfg.subscriptionId,
					nonce: cfg.nonce,
					pay_session: session,
				} )
					.done( function ( r ) {
						if ( ! r || ! r.success || ! r.data ) {
							fail( r && r.data && r.data.message );
							return;
						}
						var next = r.data.nextAction;
						if ( ! next ) {
							window.location = r.data.redirect;
							return;
						}
						try {
							next = JSON.parse( next );
						} catch ( e ) {}
						payment
							.pay( next )
							.then( function ( p ) {
								if ( p && p.error ) {
									fail();
									return;
								}
								// 正常情況 SDK 會導到 returnUrl；沒導走就手動回訂閱頁。
								window.location = r.data.redirect;
							} )
							.catch( function () {
								fail();
							} );
					} )
					.fail( function ( xhr ) {
						fail( xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message );
					} );
			} )
			.catch( function () {
				fail();
			} );
	}

	$( function () {
		mount();
		$( document.body ).on( 'click', '.moksafowo-slp-card-change-submit', submit );
	} );
} )( jQuery );
