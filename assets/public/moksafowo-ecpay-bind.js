/**
 * 綠界站內付 2.0 綁定信用卡（付款頁）。
 *
 *   1. ECPay.initialize() → ECPay.addBindingCard( token ) 在 #ECPayPayment 顯示綁卡畫面（35559、35564）。
 *   2. 按下按鈕 → ECPay.getBindCardPayToken() 取得 BindCardPayToken（35569）。
 *   3. 送到 /wc-api/moksafowo_ecpay_bind_create 建立綁定信用卡交易，依回應導到 3D 驗證頁或訂單完成頁。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoEcpayBind || {};
	var I18N = cfg.i18n || {};
	var busy = false;

	function setError( msg ) {
		var $err = $( '.moksafowo-ecpay-bind-error' );
		if ( msg ) {
			$err.text( msg ).show();
		} else {
			$err.text( '' ).hide();
		}
	}

	function setBusy( state ) {
		busy = state;
		$( '.moksafowo-ecpay-bind-submit' ).prop( 'disabled', state );
	}

	function init() {
		if ( typeof window.ECPay === 'undefined' || ! cfg.token ) {
			setError( I18N.loadError );
			return;
		}
		window.ECPay.initialize( cfg.serverType, 1, function ( errMsg ) {
			if ( errMsg ) {
				setError( I18N.loadError );
				return;
			}
			window.ECPay.addBindingCard( cfg.token, window.ECPay.Language.zhTW, function ( err ) {
				if ( err ) {
					setError( I18N.loadError );
				}
			} );
		} );
	}

	function submit() {
		if ( busy || typeof window.ECPay === 'undefined' ) {
			return;
		}
		setError( '' );
		setBusy( true );
		window.ECPay.getBindCardPayToken( function ( info, errMsg ) {
			if ( errMsg || ! info || ! info.BindCardPayToken ) {
				setBusy( false );
				setError( errMsg || I18N.payError );
				return;
			}
			$.post( cfg.createUrl, {
				order_id: cfg.orderId,
				key: cfg.orderKey,
				pay_token: info.BindCardPayToken,
			} )
				.done( function ( res ) {
					if ( res && res.success && res.data && res.data.redirect ) {
						window.location = res.data.redirect;
						return;
					}
					setBusy( false );
					setError( ( res && res.data && res.data.message ) || I18N.payError );
				} )
				.fail( function ( xhr ) {
					setBusy( false );
					var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message;
					setError( msg || I18N.payError );
				} );
		} );
	}

	$( function () {
		init();
		$( document.body ).on( 'click', '.moksafowo-ecpay-bind-submit', submit );
	} );
} )( jQuery );
