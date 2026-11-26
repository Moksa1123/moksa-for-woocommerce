/**
 * 統一金流免跳轉信用卡（UNi Embed）— 區塊結帳元件（手寫，不經 webpack）。
 *
 * 1. 元件掛上時向本站拿 SDK token，UniPayment.createSession 把三個 iframe 欄位掛進元件。
 * 2. onPaymentSetup：getTradeResult() 綁卡號，把 EncryptInfo／HashInfo 放進 paymentMethodData；
 *    伺服器（Gateways\Credit::process_payment）用同一個 token 授權。
 * 3. 結帳失敗後再要一次 token，換了就重掛、請顧客重填。
 * 4. 卡號表單載不出來時可改走統一金流付款頁（fallback）。
 *
 * 一般的統一金流付款方式由 webpack 的 methods/payuni 註冊；有 embed 設定的信用卡會在那邊跳過。
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registry = window.wc.wcBlocksRegistry;
	var settings = window.wc.wcSettings;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function ( s ) {
			return s;
		};

	var NAME = 'moksafowo_payuni_credit';

	/** 付款方式設定可能放在 paymentMethodData 物件裡，也可能是 <id>_data，兩種都讀。 */
	function getData( id ) {
		if ( ! settings || ! settings.getSetting ) {
			return null;
		}
		var bundle = settings.getSetting( 'paymentMethodData', null );
		if ( bundle && typeof bundle === 'object' && bundle[ id ] ) {
			return bundle[ id ];
		}
		return settings.getSetting( id + '_data', null );
	}

	var data = getData( NAME );
	if ( ! data || data.name !== NAME || ! data.embed ) {
		return;
	}
	var cfg = data.embed;
	var I18N = cfg.i18n || {};
	var IDS = {
		no: 'moksafowo-payuni-b-card-no',
		exp: 'moksafowo-payuni-b-card-exp',
		cvc: 'moksafowo-payuni-b-card-cvc',
		agree: 'moksafowo-payuni-b-card-token-type',
	};

	function fetchToken() {
		var body = new URLSearchParams( { action: 'moksafowo_payuni_embed_token', nonce: cfg.nonce || '' } );
		return window
			.fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( r ) {
				if ( r && r.success && r.data && r.data.token ) {
					return r.data.token;
				}
				throw new Error( ( r && r.data && r.data.message ) || '' );
			} );
	}

	function Fields( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		var st = useState( 'loading' ); // loading | ready | failed | fallback
		var phase = st[ 0 ];
		var setPhase = st[ 1 ];
		var er = useState( '' );
		var error = er[ 0 ];
		var setError = er[ 1 ];
		var ag = useState( '' );
		var agreeText = ag[ 0 ];
		var setAgreeText = ag[ 1 ];

		var sdkRef = useRef( null );
		var tokenRef = useRef( '' );
		var fieldsRef = useRef( { CardNo: null, CardExp: null, CardCvc: null } );
		var phaseRef = useRef( 'loading' );
		phaseRef.current = phase;

		function start( tk ) {
			[ IDS.no, IDS.exp, IDS.cvc, IDS.agree ].forEach( function ( id ) {
				var n = document.getElementById( id );
				if ( n ) {
					n.innerHTML = '';
				}
			} );
			fieldsRef.current = { CardNo: null, CardExp: null, CardCvc: null };
			setAgreeText( '' );
			if ( typeof window.UniPayment === 'undefined' ) {
				return Promise.reject( new Error( I18N.failed ) );
			}
			var sdk = window.UniPayment.createSession( tk, {
				env: cfg.env === 'S' ? 'S' : 'P',
				useInst: false,
				elements: { CardNo: IDS.no, CardExp: IDS.exp, CardCvc: IDS.cvc, CardTokenType: IDS.agree },
				style: { color: '#1e1e1e', errorColor: '#b32d2e', fontSize: '16px', lineHeight: '24px' },
			} );
			sdk.onUpdate( function ( u ) {
				if ( ! u ) {
					return;
				}
				if ( u.status ) {
					var f = Object.assign( {}, fieldsRef.current );
					[ 'CardNo', 'CardExp', 'CardCvc' ].forEach( function ( k ) {
						if ( Object.prototype.hasOwnProperty.call( u.status, k ) ) {
							f[ k ] = u.status[ k ];
						}
					} );
					fieldsRef.current = f;
				}
				if ( u.event === 'useTokenType' && u.data ) {
					setAgreeText( u.data.tokenTypeText || '' );
				}
			} );
			sdkRef.current = sdk;
			tokenRef.current = tk;
			// SDK 連不上 iframe 時只在 console 報錯、不一定會結束 start()，自己設上限。
			return Promise.race( [
				sdk.start(),
				new Promise( function ( resolve, reject ) {
					setTimeout( function () {
						reject( new Error( I18N.failed ) );
					}, 20000 );
				} ),
			] );
		}

		useEffect( function () {
			var alive = true;
			fetchToken()
				.then( function ( tk ) {
					return start( tk );
				} )
				.then( function () {
					if ( alive ) {
						setPhase( 'ready' );
					}
				} )
				.catch( function ( e ) {
					if ( alive ) {
						setPhase( 'failed' );
						setError( ( e && e.message ) || I18N.failed );
					}
				} );
			return function () {
				alive = false;
			};
		}, [] );

		useEffect(
			function () {
				return eventRegistration.onPaymentSetup( function () {
					if ( phaseRef.current === 'fallback' ) {
						return {
							type: emitResponse.responseTypes.SUCCESS,
							meta: { paymentMethodData: { moksafowo_payuni_embed_fallback: '1' } },
						};
					}
					var f = fieldsRef.current;
					if ( phaseRef.current !== 'ready' || ! sdkRef.current ) {
						return { type: emitResponse.responseTypes.ERROR, message: I18N.failed };
					}
					if ( ! ( f.CardNo === true && f.CardExp === true && f.CardCvc === true ) ) {
						return { type: emitResponse.responseTypes.ERROR, message: I18N.incomplete };
					}
					return sdkRef.current.getTradeResult().then(
						function ( r ) {
							if ( ! r || r.Status !== 'SUCCESS' || ! r.EncryptInfo || ! r.HashInfo ) {
								return { type: emitResponse.responseTypes.ERROR, message: I18N.bindFailed };
							}
							return {
								type: emitResponse.responseTypes.SUCCESS,
								meta: {
									paymentMethodData: {
										moksafowo_payuni_embed_enc: r.EncryptInfo,
										moksafowo_payuni_embed_hash: r.HashInfo,
									},
								},
							};
						},
						function () {
							return { type: emitResponse.responseTypes.ERROR, message: I18N.bindFailed };
						}
					);
				} );
			},
			[ eventRegistration.onPaymentSetup ]
		);

		// 結帳失敗：伺服器已送出授權的 token 會換新；換了就重掛，請顧客重填。
		useEffect(
			function () {
				var on = eventRegistration.onCheckoutFail || eventRegistration.onCheckoutAfterProcessingWithError;
				if ( ! on ) {
					return undefined;
				}
				return on( function () {
					if ( phaseRef.current !== 'ready' ) {
						return true;
					}
					fetchToken().then( function ( tk ) {
						if ( tk !== tokenRef.current ) {
							start( tk ).then( function () {
								setError( I18N.reenter );
							} );
						}
					} );
					return true;
				} );
			},
			[ eventRegistration.onCheckoutFail, eventRegistration.onCheckoutAfterProcessingWithError ]
		);

		var children = [];
		if ( data.description ) {
			children.push( el( 'p', { key: 'd' }, decode( data.description ) ) );
		}
		if ( phase === 'loading' ) {
			children.push( el( 'p', { key: 's', className: 'moksafowo-payuni-embed__status' }, I18N.loading ) );
		}
		children.push(
			el(
				'div',
				{ key: 'f', className: 'moksafowo-payuni-embed__fields', style: { display: phase === 'ready' ? 'block' : 'none' } },
				el( 'label', { className: 'moksafowo-payuni-embed__label', htmlFor: IDS.no }, I18N.cardNumber ),
				el( 'div', { id: IDS.no, className: 'moksafowo-payuni-embed__field' } ),
				el(
					'div',
					{ className: 'moksafowo-payuni-embed__row' },
					el(
						'div',
						null,
						el( 'label', { className: 'moksafowo-payuni-embed__label', htmlFor: IDS.exp }, I18N.expiry ),
						el( 'div', { id: IDS.exp, className: 'moksafowo-payuni-embed__field' } )
					),
					el(
						'div',
						null,
						el( 'label', { className: 'moksafowo-payuni-embed__label', htmlFor: IDS.cvc }, I18N.cvc ),
						el( 'div', { id: IDS.cvc, className: 'moksafowo-payuni-embed__field' } )
					)
				),
				el(
					'div',
					{ className: 'moksafowo-payuni-embed__agree', style: { display: agreeText ? 'flex' : 'none' } },
					el( 'div', { id: IDS.agree } ),
					el( 'p', { className: 'moksafowo-payuni-embed__agree-text' }, agreeText )
				)
			)
		);
		if ( error ) {
			children.push( el( 'p', { key: 'e', className: 'moksafowo-payuni-embed__error', role: 'alert' }, error ) );
		}
		if ( phase === 'failed' ) {
			children.push(
				el(
					'button',
					{
						key: 'b',
						type: 'button',
						className: 'button moksafowo-payuni-embed__fallback',
						onClick: function () {
							setPhase( 'fallback' );
							setError( '' );
						},
					},
					I18N.fallback
				)
			);
		}
		return el( 'div', { className: 'moksafowo-payuni-embed' }, children );
	}

	var Label = function ( props ) {
		var C = props.components.PaymentMethodLabel;
		return el( C, { text: decode( data.title || data.name ) } );
	};

	registry.registerPaymentMethod( {
		name: NAME,
		label: el( Label, null ),
		ariaLabel: decode( data.title || 'PAYUNi' ),
		content: el( Fields, null ),
		edit: el( 'div', null, decode( data.description || '' ) ),
		canMakePayment: function () {
			return true;
		},
		paymentMethodId: NAME,
		supports: { features: data.supports || [ 'products' ] },
	} );
} )();
