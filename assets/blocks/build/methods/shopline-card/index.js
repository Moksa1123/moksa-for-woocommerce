/**
 * Shopline Payments 內嵌式信用卡（區塊結帳）— 手寫、不經 webpack。
 *
 *   1. content 元件掛載時以 ShoplinePayments() 初始化 SDK 收銀台。
 *   2. onPaymentSetup：payment.createPayment() 取 paySession，放進 paymentMethodData
 *      （CardGateway::process_payment 讀 moksafowo_slp_pay_session）。
 *   3. onCheckoutSuccess：伺服器回傳的 nextAction 交給 payment.pay()，SDK 完成 3D 驗證後
 *      導到訂單完成頁。
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
		return;
	}

	var registry = window.wc.wcBlocksRegistry;
	var el = window.wp.element.createElement;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var useState = window.wp.element.useState;
	var settings = window.wc.wcSettings;

	var NAME = 'moksafowo_shopline_card';

	// WC 11.1 起付款方式設定合併成 paymentMethodData，舊版是 <id>_data，兩種都吃。
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
	if ( ! data || data.name !== NAME ) {
		return;
	}
	var I18N = data.i18n || {};
	var CONTAINER_ID = 'moksafowo-slp-card-block-container';

	// cartTotal.value 是最小貨幣單位；SDK 要的是元 × 100。
	function toSdkAmount( billing ) {
		var total = billing && billing.cartTotal ? parseInt( billing.cartTotal.value, 10 ) || 0 : 0;
		var unit = billing && billing.currency ? parseInt( billing.currency.minorUnit, 10 ) || 0 : 0;
		return Math.max( Math.round( ( total / Math.pow( 10, unit ) ) * 100 ), 100 );
	}

	function Content( props ) {
		var paymentRef = useRef( null );
		var state = useState( '' );
		var error = state[ 0 ];
		var setError = state[ 1 ];
		var emit = props.emitResponse;
		var amount = toSdkAmount( props.billing );

		useEffect( function () {
			var cancelled = false;
			if ( ! window.ShoplinePayments ) {
				setError( I18N.loadError || '' );
				return undefined;
			}
			var opts = {
				clientKey: data.clientKey,
				merchantId: data.merchantId,
				paymentMethod: 'CreditCard',
				currency: 'TWD',
				amount: amount,
				element: '#' + CONTAINER_ID,
				env: data.env === 'production' ? 'production' : 'sandbox',
			};
			if ( data.bindCard ) {
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
			window
				.ShoplinePayments( opts )
				.then( function ( res ) {
					if ( cancelled ) {
						if ( res && res.payment ) {
							res.payment.destroy();
						}
						return;
					}
					if ( ! res || res.error ) {
						setError( I18N.loadError || '' );
						return;
					}
					paymentRef.current = res.payment;
				} )
				.catch( function () {
					setError( I18N.loadError || '' );
				} );
			return function () {
				cancelled = true;
				if ( paymentRef.current ) {
					try {
						paymentRef.current.destroy();
					} catch ( e ) {}
					paymentRef.current = null;
				}
			};
		}, [] );

		// 運費、折扣改變時同步 SDK 金額（大額交易驗證依金額決定要不要多問身分資料）。
		useEffect(
			function () {
				if ( paymentRef.current && paymentRef.current.update ) {
					paymentRef.current.update( { amount: amount } );
				}
			},
			[ amount ]
		);

		useEffect(
			function () {
				return props.eventRegistration.onPaymentSetup( function () {
					var payment = paymentRef.current;
					if ( ! payment ) {
						return { type: emit.responseTypes.ERROR, message: I18N.loadError || '' };
					}
					return payment.createPayment().then( function ( res ) {
						if ( ! res || res.error || ! res.paySession ) {
							// 卡號錯誤時 SDK 會在欄位下方提示。
							return {
								type: emit.responseTypes.ERROR,
								message: ( res && res.error && res.error.message ) || I18N.payError || '',
							};
						}
						var session = typeof res.paySession === 'string' ? res.paySession : JSON.stringify( res.paySession );
						return {
							type: emit.responseTypes.SUCCESS,
							meta: { paymentMethodData: { moksafowo_slp_pay_session: session } },
						};
					} );
				} );
			},
			[ props.eventRegistration.onPaymentSetup ]
		);

		useEffect(
			function () {
				return props.eventRegistration.onCheckoutSuccess( function ( result ) {
					var details = ( result && result.processingResponse && result.processingResponse.paymentDetails ) || {};
					var next = details.moksafowo_slp_next_action;
					var payment = paymentRef.current;
					if ( ! next || ! payment ) {
						return true;
					}
					try {
						next = JSON.parse( next );
					} catch ( e ) {}
					return payment.pay( next ).then( function ( res ) {
						if ( res && res.error ) {
							return {
								type: emit.responseTypes.FAIL,
								message: I18N.payError || '',
								messageContext: emit.noticeContexts.PAYMENTS,
							};
						}
						return true;
					} );
				} );
			},
			[ props.eventRegistration.onCheckoutSuccess ]
		);

		return el(
			'div',
			{ className: 'moksafowo-slp-card-block' },
			data.description ? el( 'p', null, data.description ) : null,
			el( 'div', { id: CONTAINER_ID } ),
			error ? el( 'p', { role: 'alert', style: { color: '#b32d2e' } }, error ) : null
		);
	}

	function Label() {
		return el( 'span', null, data.title || 'Credit card' );
	}

	registry.registerPaymentMethod( {
		name: NAME,
		label: el( Label ),
		content: el( Content ),
		edit: el( 'div', null, data.description || '' ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: data.title || NAME,
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )();
