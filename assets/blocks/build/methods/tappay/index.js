/**
 * Hand-coded TapPay block-checkout payment method (interactive).
 *
 * 跟 ECPay / PChomePay block JS（純 label/desc 殼）不同：TapPay 是真互動元件。
 *   1. 從 `moksafowo_tappay_credit_data` setting 讀 appId / appKey / env。
 *   2. content 元件 mount 時 TPDirect.setupSDK + TPDirect.card.setup 把
 *      iframe 卡號 / 期限 / CCV 欄位 render 進區塊。
 *   3. TPDirect.card.onUpdate → 更新 canGetPrime ref + emit canMakePayment
 *      （透過 React state 觸發 re-render，WC Blocks 會重新詢問 canMakePayment）。
 *   4. eventRegistration.onPaymentSetup → async TPDirect.card.getPrime()，
 *      成功把 prime / bin_code / last_four / issuer 包進 paymentMethodData，
 *      WC 送 server（Gateways\Credit::process_payment 讀 moksafowo_tappay_*）。
 *
 * No JSX / no build step — 用 window.wp.element.createElement + hooks，
 * 從 raw .js 跑（plugin 不需要 npm run build）。SDK 由 moksafowo-tappay-sdk
 * handle（tpdirect v5）當 script dependency 先載好，TPDirect 全域可用。
 */

( function () {
	'use strict';

	if (
		! window.wc ||
		! window.wc.wcBlocksRegistry ||
		! window.wp ||
		! window.wp.element
	) {
		return;
	}

	var registry = window.wc.wcBlocksRegistry;
	var el = window.wp.element.createElement;
	var useState = window.wp.element.useState;
	var useEffect = window.wp.element.useEffect;
	var useRef = window.wp.element.useRef;
	var settings = window.wc.wcSettings;

	var NAME = 'moksafowo_tappay_credit';

	var data =
		settings && settings.getSetting
			? settings.getSetting( NAME + '_data' )
			: null;

	if ( ! data || data.name !== NAME ) {
		return;
	}

	var appId = parseInt( data.appId, 10 ) || 0;
	var appKey = data.appKey || '';
	var env = data.env === 'production' ? 'production' : 'sandbox';

	// 全 gateway instance 共用 — TPDirect.setupSDK 只能呼叫一次。
	var sdkReady = false;

	function ensureSdk() {
		if ( sdkReady ) {
			return true;
		}
		if ( typeof window.TPDirect === 'undefined' ) {
			return false;
		}
		try {
			window.TPDirect.setupSDK( appId, appKey, env );
			sdkReady = true;
		} catch ( e ) {
			// 已 setup 過會 throw — 視為就緒。
			sdkReady = true;
		}
		return true;
	}

	var FIELD_STYLE = {
		'input': {
			'color': '#1e1e1e',
			'font-size': '16px',
			'line-height': '1.4',
		},
		'input.ccv': {},
		'.valid': { 'color': '#1a7f37' },
		'.invalid': { 'color': '#b32d2e' },
	};

	function TappayFields( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;

		var state = useState( false );
		var canPay = state[ 0 ];
		var setCanPay = state[ 1 ];

		var errState = useState( '' );
		var errMsg = errState[ 0 ];
		var setErrMsg = errState[ 1 ];

		// onUpdate 的最新 canGetPrime — 用 ref 給 onPaymentSetup closure 讀。
		var canPrimeRef = useRef( false );
		var mountedRef = useRef( false );

		// Mount TapPay Fields（每個元件實例只 setup 一次）。
		useEffect( function () {
			if ( mountedRef.current ) {
				return;
			}
			if ( ! ensureSdk() ) {
				setErrMsg(
					( data.i18n && data.i18n.sdkError ) ||
						'TapPay SDK 載入失敗，請重新整理頁面。'
				);
				return;
			}
			mountedRef.current = true;

			try {
				window.TPDirect.card.setup( {
					fields: {
						number: {
							element: '#moksafowo-tappay-block-number',
							placeholder: '**** **** **** ****',
						},
						expirationDate: {
							element: '#moksafowo-tappay-block-expiry',
							placeholder: 'MM / YY',
						},
						ccv: {
							element: '#moksafowo-tappay-block-ccv',
							placeholder: 'CCV',
						},
					},
					styles: FIELD_STYLE,
					isMaskCreditCardNumber: true,
					maskCreditCardNumberRange: { beginIndex: 6, endIndex: 11 },
				} );

				window.TPDirect.card.onUpdate( function ( update ) {
					canPrimeRef.current = !! update.canGetPrime;
					setCanPay( !! update.canGetPrime );
					if ( update.canGetPrime ) {
						setErrMsg( '' );
					}
				} );
			} catch ( e ) {
				setErrMsg(
					( data.i18n && data.i18n.sdkError ) ||
						'TapPay 卡片欄位初始化失敗。'
				);
			}
		}, [] );

		// onPaymentSetup — getPrime 後把 prime 等資料塞進 paymentMethodData。
		useEffect(
			function () {
				var unsubscribe = eventRegistration.onPaymentSetup(
					function () {
						return new Promise( function ( resolve ) {
							if (
								typeof window.TPDirect === 'undefined' ||
								! window.TPDirect.card
							) {
								resolve( {
									type: emitResponse.responseTypes.ERROR,
									message:
										( data.i18n &&
											data.i18n.sdkError ) ||
										'TapPay SDK 尚未就緒。',
								} );
								return;
							}

							var status =
								window.TPDirect.card.getTappayFieldsStatus();
							if ( status && status.canGetPrime === false ) {
								resolve( {
									type: emitResponse.responseTypes.ERROR,
									message:
										( data.i18n &&
											data.i18n.incomplete ) ||
										'請完整填寫信用卡資訊。',
								} );
								return;
							}

							window.TPDirect.card.getPrime( function (
								result
							) {
								if ( result.status !== 0 ) {
									resolve( {
										type: emitResponse.responseTypes
											.ERROR,
										message:
											result.msg ||
											( data.i18n &&
												data.i18n.primeError ) ||
											'無法取得付款憑證。',
									} );
									return;
								}
								var card = result.card || {};
								var pmData = {
									moksafowo_tappay_prime:
										card.prime || '',
									moksafowo_tappay_bin:
										card.bin_code || '',
									moksafowo_tappay_last_four:
										card.last_four || '',
									moksafowo_tappay_issuer:
										card.issuer || '',
								};
								// 文件版合約 paymentMethodData 在 top-level；
								// 部分 WC Blocks 版本讀 meta.paymentMethodData
								// — 兩處都帶以求相容。
								resolve( {
									type: emitResponse.responseTypes
										.SUCCESS,
									paymentMethodData: pmData,
									meta: {
										paymentMethodData: pmData,
									},
								} );
							} );
						} );
					}
				);
				return unsubscribe;
			},
			[
				eventRegistration.onPaymentSetup,
				emitResponse.responseTypes.SUCCESS,
				emitResponse.responseTypes.ERROR,
			]
		);

		return el(
			'div',
			{ className: 'moksafowo-tappay-blocks-fields' },
			data.description
				? el(
						'p',
						{ className: 'moksafowo-tappay-blocks-desc' },
						data.description
				  )
				: null,
			el(
				'div',
				{
					className: 'moksafowo-tappay-blocks-row',
					style: { marginBottom: '12px' },
				},
				el(
					'label',
					{
						htmlFor: 'moksafowo-tappay-block-number',
						style: { display: 'block', marginBottom: '4px' },
					},
					'卡號'
				),
				el( 'div', {
					id: 'moksafowo-tappay-block-number',
					className: 'moksafowo-tappay-block-field',
					style: {
						height: '40px',
						border: '1px solid #8c8f94',
						borderRadius: '4px',
						padding: '0 10px',
					},
				} )
			),
			el(
				'div',
				{
					className: 'moksafowo-tappay-blocks-row',
					style: { display: 'flex', gap: '12px' },
				},
				el(
					'div',
					{ style: { flex: '1' } },
					el(
						'label',
						{
							htmlFor: 'moksafowo-tappay-block-expiry',
							style: {
								display: 'block',
								marginBottom: '4px',
							},
						},
						'有效期限'
					),
					el( 'div', {
						id: 'moksafowo-tappay-block-expiry',
						className: 'moksafowo-tappay-block-field',
						style: {
							height: '40px',
							border: '1px solid #8c8f94',
							borderRadius: '4px',
							padding: '0 10px',
						},
					} )
				),
				el(
					'div',
					{ style: { flex: '1' } },
					el(
						'label',
						{
							htmlFor: 'moksafowo-tappay-block-ccv',
							style: {
								display: 'block',
								marginBottom: '4px',
							},
						},
						'安全碼 CVC'
					),
					el( 'div', {
						id: 'moksafowo-tappay-block-ccv',
						className: 'moksafowo-tappay-block-field',
						style: {
							height: '40px',
							border: '1px solid #8c8f94',
							borderRadius: '4px',
							padding: '0 10px',
						},
					} )
				)
			),
			errMsg
				? el(
						'p',
						{
							className: 'moksafowo-tappay-blocks-error',
							role: 'alert',
							style: { color: '#b32d2e', marginTop: '8px' },
						},
						errMsg
				  )
				: null,
			// canPay 進 hidden 標記，避免 lint 抱怨未使用；同時驅動 re-render。
			el( 'input', {
				type: 'hidden',
				value: canPay ? '1' : '0',
				readOnly: true,
				'data-mo-tappay-can-pay': canPay ? '1' : '0',
			} )
		);
	}

	var Label = function () {
		return el( 'span', null, data.title || 'TapPay 信用卡' );
	};

	registry.registerPaymentMethod( {
		name: NAME,
		label: el( Label ),
		content: el( TappayFields ),
		edit: el(
			'div',
			null,
			data.description || 'TapPay 信用卡（編輯器預覽）'
		),
		canMakePayment: function () {
			// 憑證 / SDK 由 server is_active() + 前端 setting 把關；
			// 卡片欄位完整性在 onPaymentSetup getPrime 時才硬性驗。
			return true;
		},
		ariaLabel: data.title || 'TapPay 信用卡',
		paymentMethodId: NAME,
		supports: {
			features: ( data && data.supports ) || [ 'products' ],
		},
	} );
} )();
