/**
 * 訂單查號 — WP 命令面板（Ctrl+K）整合。
 *
 * 註冊一個 command loader：使用者在命令面板打發票號 / 物流單號 / 金流交易序號,
 * 即時呼叫 moksa-for-woocommerce/v1/order-lookup,把符合的訂單列成可點指令 → 點了跳訂單編輯頁。
 */
( function ( wp ) {
	if ( ! wp || ! wp.data || ! wp.element || ! wp.apiFetch ) {
		return;
	}

	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var apiFetch = wp.apiFetch;
	var createElement = wp.element.createElement;

	// 命令面板指令前的圖示 —— 用 dashicon SVG 自繪,不依賴 wp-icons（該 handle 在此環境未暴露 wp.icons,會害 loader 不註冊）。
	var ORDER_ICON = createElement(
		'span',
		{ className: 'dashicons dashicons-cart', style: { fontSize: '20px', width: '20px', height: '20px' } }
	);

	function useOrderLookupCommands( props ) {
		var search = ( props && props.search ) || '';
		var stateCommands = useState( [] );
		var commands = stateCommands[ 0 ];
		var setCommands = stateCommands[ 1 ];
		var stateLoading = useState( false );
		var isLoading = stateLoading[ 0 ];
		var setIsLoading = stateLoading[ 1 ];

		useEffect(
			function () {
				var term = search.trim();
				if ( term.length < 3 ) {
					setCommands( [] );
					setIsLoading( false );
					return undefined;
				}

				var active = true;
				setIsLoading( true );

				var timer = setTimeout( function () {
					apiFetch( {
						path:
							'/moksa-for-woocommerce/v1/order-lookup?number=' +
							encodeURIComponent( term ),
					} )
						.then( function ( results ) {
							if ( ! active ) {
								return;
							}
							setCommands(
								( results || [] ).map( function ( order ) {
									return {
										name: 'moksa-for-woocommerce/order-' + order.id,
										label: order.label,
										icon: ORDER_ICON,
										// 命令面板(cmdk)會用搜尋字串再過濾一次,
										// label 不含號碼會被濾掉 → searchLabel 帶上號碼確保命中.
										searchLabel: term + ' ' + order.label,
										callback: function ( args ) {
											if ( args && args.close ) {
												args.close();
											}
											window.location.href =
												order.edit_url;
										},
									};
								} )
							);
							setIsLoading( false );
						} )
						.catch( function () {
							if ( ! active ) {
								return;
							}
							setCommands( [] );
							setIsLoading( false );
						} );
				}, 250 );

				return function () {
					active = false;
					clearTimeout( timer );
				};
			},
			[ search ]
		);

		return { commands: commands, isLoading: isLoading };
	}

	wp.data.dispatch( 'core/commands' ).registerCommandLoader( {
		name: 'moksa-for-woocommerce/order-lookup',
		hook: useOrderLookupCommands,
	} );
} )( window.wp );
