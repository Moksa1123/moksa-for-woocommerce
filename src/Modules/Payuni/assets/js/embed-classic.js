/**
 * 統一金流免跳轉信用卡（UNi Embed）— 傳統結帳與訂單付款頁。
 *
 * 1. 選到統一金流信用卡時向本站拿 SDK token，UniPayment.createSession 把卡號、到期日、末三碼
 *    三個 iframe 掛進 Credit::payment_fields() 的容器。
 * 2. 下單時先 getTradeResult()（把卡號綁到 token），結果放進隱藏欄位再送出；伺服器用同一個
 *    token 授權。
 * 3. 結帳失敗後向本站再要一次 token：伺服器已送出授權的 token 會換新，換了就重掛欄位，顧客重填。
 * 4. 卡號表單載不出來時可以改走統一金流付款頁（fallback）。
 *
 * 只在選了統一金流信用卡時攔截送出，其他付款方式不受影響。
 */
( function ( $ ) {
	'use strict';

	var cfg = window.moksafowoPayuniEmbed || {};
	var GATEWAY = cfg.gatewayId || 'moksafowo_payuni_credit';
	var I18N = cfg.i18n || {};

	var token = '';
	var tokenExpires = 0;
	var mountedToken = '';
	var sdk = null;
	var ready = false;
	var fields = { CardNo: null, CardExp: null, CardCvc: null };
	var loading = false;
	var loadingEl = null;
	var generation = 0;
	var submitting = false;
	var START_TIMEOUT = 20000;

	function $box() {
		return $( '.moksafowo-payuni-embed[data-gateway="' + GATEWAY + '"]' );
	}

	function selected() {
		return $( 'input[name="payment_method"]:checked' ).val() === GATEWAY;
	}

	function setError( msg ) {
		var $err = $box().find( '.moksafowo-payuni-embed__error' );
		if ( msg ) {
			$err.text( msg ).show();
		} else {
			$err.text( '' ).hide();
		}
	}

	function setHidden( name, value ) {
		$box().find( 'input[name="' + name + '"]' ).val( value );
	}

	function fallbackOn() {
		return '1' === $box().find( 'input[name="moksafowo_payuni_embed_fallback"]' ).val();
	}

	/** 卡號表單載不出來：顯示原因，給一顆改走統一金流付款頁的按鈕。 */
	function showFallback( msg ) {
		var $b = $box();
		$b.find( '.moksafowo-payuni-embed__status' ).hide();
		$b.find( '.moksafowo-payuni-embed__fields' ).hide();
		setError( msg || I18N.failed );
		if ( ! $b.find( '.moksafowo-payuni-embed__fallback' ).length ) {
			$( '<button type="button" class="button moksafowo-payuni-embed__fallback"></button>' )
				.text( I18N.fallback || '' )
				.on( 'click', function () {
					setHidden( 'moksafowo_payuni_embed_fallback', '1' );
					setError( '' );
					$( this ).prop( 'disabled', true );
				} )
				.appendTo( $b );
		}
	}

	function fetchToken() {
		var body = { action: 'moksafowo_payuni_embed_token', nonce: cfg.nonce || '' };
		if ( cfg.orderId ) {
			body.order_id = cfg.orderId;
			body.order_key = cfg.orderKey || '';
		}
		return $.ajax( { url: cfg.ajaxUrl, method: 'POST', data: body, dataType: 'json' } ).then(
			function ( r ) {
				if ( r && r.success && r.data && r.data.token ) {
					token = r.data.token;
					tokenExpires = parseInt( r.data.expires, 10 ) || 0;
					return token;
				}
				return $.Deferred().reject( r && r.data ? r.data.message : '' ).promise();
			},
			function ( xhr ) {
				var r = xhr && xhr.responseJSON;
				return $.Deferred().reject( r && r.data ? r.data.message : '' ).promise();
			}
		);
	}

	function mount() {
		var $b = $box();
		if ( ! $b.length || ! selected() || fallbackOn() ) {
			return;
		}
		var $no = $b.find( '#moksafowo-payuni-card-no' );
		// WC 的 update_order_review 會把付款欄位整塊換掉：容器空了、或 token 換了才重掛。
		if ( $no.children().length > 0 && mountedToken === token && token ) {
			return;
		}
		// 同一個容器已經在載入就等它；容器被換掉了，舊的那次作廢、對新容器重來。
		if ( loading && loadingEl === $no[ 0 ] ) {
			return;
		}
		if ( typeof window.UniPayment === 'undefined' ) {
			showFallback( I18N.failed );
			return;
		}
		var mine = ++generation;
		loading = true;
		loadingEl = $no[ 0 ];
		var needToken = ! token || tokenExpires - Date.now() / 1000 < 120;
		var p = needToken ? fetchToken() : $.Deferred().resolve( token ).promise();
		p.then( function ( tk ) {
			return mine === generation ? start( tk ) : $.Deferred().resolve().promise();
		} ).fail( function ( msg ) {
			if ( mine === generation ) {
				showFallback( msg || I18N.failed );
			}
		} ).always( function () {
			if ( mine === generation ) {
				loading = false;
				loadingEl = null;
			}
		} );
	}

	function start( tk ) {
		var $b = $box();
		var d = $.Deferred();
		// SDK 連不上 iframe 時只在 console 報錯、不一定會結束 start()，自己設上限。
		var timer = setTimeout( function () {
			d.reject( I18N.failed );
		}, START_TIMEOUT );
		d.always( function () {
			clearTimeout( timer );
		} );
		$b.find( '#moksafowo-payuni-card-no, #moksafowo-payuni-card-exp, #moksafowo-payuni-card-cvc, #moksafowo-payuni-card-token-type' ).empty();
		$b.find( '.moksafowo-payuni-embed__agree' ).hide();
		ready = false;
		fields = { CardNo: null, CardExp: null, CardCvc: null };
		try {
			sdk = window.UniPayment.createSession( tk, {
				env: cfg.env === 'S' ? 'S' : 'P',
				useInst: false,
				elements: {
					CardNo: 'moksafowo-payuni-card-no',
					CardExp: 'moksafowo-payuni-card-exp',
					CardCvc: 'moksafowo-payuni-card-cvc',
					CardTokenType: 'moksafowo-payuni-card-token-type',
				},
				style: { color: '#1e1e1e', errorColor: '#b32d2e', fontSize: '16px', lineHeight: '24px' },
			} );
			sdk.onUpdate( onUpdate );
			sdk.start().then(
				function () {
					mountedToken = tk;
					ready = true;
					$b.find( '.moksafowo-payuni-embed__status' ).hide();
					$b.find( '.moksafowo-payuni-embed__fields' ).show();
					d.resolve();
				},
				function () {
					d.reject( I18N.failed );
				}
			);
		} catch ( e ) {
			d.reject( I18N.failed );
		}
		return d.promise();
	}

	function onUpdate( update ) {
		if ( ! update ) {
			return;
		}
		if ( update.status ) {
			[ 'CardNo', 'CardExp', 'CardCvc' ].forEach( function ( k ) {
				if ( Object.prototype.hasOwnProperty.call( update.status, k ) ) {
					fields[ k ] = update.status[ k ];
				}
			} );
			if ( complete() ) {
				setError( '' );
			}
		}
		// 要約定信用卡（訂閱）：統一金流給一段說明，照官方建議顯示給顧客。
		if ( update.event === 'useTokenType' && update.data ) {
			var $agree = $box().find( '.moksafowo-payuni-embed__agree' );
			$agree.find( '.moksafowo-payuni-embed__agree-text' ).text( update.data.tokenTypeText || '' );
			$agree.show();
		}
	}

	function complete() {
		return fields.CardNo === true && fields.CardExp === true && fields.CardCvc === true;
	}

	/** 先綁卡號（getTradeResult），成功後把結果放進隱藏欄位，交給 done 送出。 */
	function bindCard( done ) {
		if ( ! sdk || ! ready ) {
			setError( I18N.failed );
			return;
		}
		if ( ! complete() ) {
			setError( I18N.incomplete );
			return;
		}
		submitting = true;
		setError( '' );
		sdk.getTradeResult().then(
			function ( r ) {
				submitting = false;
				if ( ! r || r.Status !== 'SUCCESS' || ! r.EncryptInfo || ! r.HashInfo ) {
					setError( I18N.bindFailed );
					return;
				}
				setHidden( 'moksafowo_payuni_embed_enc', r.EncryptInfo );
				setHidden( 'moksafowo_payuni_embed_hash', r.HashInfo );
				done();
			},
			function () {
				submitting = false;
				setError( I18N.bindFailed );
			}
		);
	}

	function onPlaceOrder() {
		if ( ! selected() || fallbackOn() ) {
			return true;
		}
		if ( $box().find( 'input[name="moksafowo_payuni_embed_enc"]' ).val() ) {
			return true;
		}
		if ( ! submitting ) {
			bindCard( function () {
				$( 'form.checkout' ).trigger( 'submit' );
			} );
		}
		return false;
	}

	// WC 對表單用 triggerHandler 觸發 checkout_place_order_<id>，不會冒泡：一定要綁在 form.checkout 上。
	function bindPlaceOrder() {
		var evt = 'checkout_place_order_' + GATEWAY;
		$( 'form.checkout' ).off( evt + '.moksafowoPayuni' ).on( evt + '.moksafowoPayuni', onPlaceOrder );
	}

	// 訂單付款頁（form#order_review）原生送出，用 capture 先攔下來綁卡號。
	function bindOrderReview() {
		var form = document.getElementById( 'order_review' );
		if ( ! form || form.getAttribute( 'data-moksafowo-payuni-embed' ) ) {
			return;
		}
		form.setAttribute( 'data-moksafowo-payuni-embed', '1' );
		form.addEventListener(
			'submit',
			function ( e ) {
				if ( ! selected() || fallbackOn() || $box().find( 'input[name="moksafowo_payuni_embed_enc"]' ).val() ) {
					return;
				}
				e.preventDefault();
				e.stopImmediatePropagation();
				if ( submitting ) {
					return;
				}
				bindCard( function () {
					var btn = document.getElementById( 'place_order' );
					if ( form.requestSubmit ) {
						form.requestSubmit( btn || undefined );
					} else {
						form.submit();
					}
				} );
			},
			true
		);
	}

	/** 結帳失敗：清掉這次的結果；伺服器若已用掉 token 會給新的，換了就重掛，請顧客重填。 */
	function onCheckoutError() {
		if ( ! selected() || fallbackOn() ) {
			return;
		}
		setHidden( 'moksafowo_payuni_embed_enc', '' );
		setHidden( 'moksafowo_payuni_embed_hash', '' );
		fetchToken().then( function ( tk ) {
			if ( tk !== mountedToken ) {
				start( tk ).then( function () {
					setError( I18N.reenter );
				} );
			}
		} );
	}

	$( document.body ).on( 'updated_checkout', function () {
		bindPlaceOrder();
		mount();
	} );
	$( document.body ).on( 'payment_method_selected', mount );
	$( document.body ).on( 'change', 'input[name="payment_method"]', mount );
	$( document.body ).on( 'checkout_error', onCheckoutError );

	$( function () {
		bindPlaceOrder();
		bindOrderReview();
		mount();
	} );
} )( jQuery );
