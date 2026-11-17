/**
 * 台灣鄉鎮市區下拉 — Block checkout。
 *
 * moksafowo/district select 由 WC Blocks register_additional_checkout_field 註冊（一次列入
 * 全 370 options），這支 JS 只做兩件事：
 *   1. 監聽 country / state select 變更 → 隱藏不屬於該縣市的 options
 *   2. 監聽 moksafowo/district select 變更 → 自動帶 postcode
 *
 * 寫值一律走 wc/store/cart 的 setShippingAddress / setBillingAddress，並延到 React 處理完
 * 當下的 change 之後。直接改 DOM 再派事件時，React 會拿舊的地址物件存檔，把剛選的值蓋掉。
 */
( function () {
	'use strict';

	if ( typeof moksafowo_tw_district === 'undefined' ) {
		return;
	}

	const FIELD_ID    = moksafowo_tw_district.field_id;
	const BY_STATE    = moksafowo_tw_district.by_state || {};
	const POSTCODES   = moksafowo_tw_district.postcodes || {};
	const PLACEHOLDER = moksafowo_tw_district.placeholder || 'Select…';

	function fieldKey( prefix ) {
		// Block 把 moksafowo/district 渲染成 id="<group>-moksafowo-district"（slash 換 dash）
		// 例：shipping-moksafowo-district / billing-moksafowo-district
		return prefix + '-' + FIELD_ID.replace( /\//g, '-' );
	}

	function getSelect( prefix ) {
		return document.getElementById( fieldKey( prefix ) );
	}

	function getStateValue( prefix ) {
		const stateSel = document.getElementById( prefix + '-state' );
		return stateSel ? stateSel.value : '';
	}

	function getCountryValue( prefix ) {
		const countrySel = document.getElementById( prefix + '-country' );
		return countrySel ? countrySel.value : '';
	}

	/** 過濾 options：state 變更時只 show 屬於該 state 的選項。 */
	function filterOptions( prefix ) {
		const select = getSelect( prefix );
		if ( ! select ) {
			return;
		}
		const country = getCountryValue( prefix );
		const state   = getStateValue( prefix );
		const allowed = ( country === 'TW' && state && BY_STATE[ state ] )
			? new Set( BY_STATE[ state ] )
			: null;

		Array.from( select.options ).forEach( function ( opt ) {
			if ( '' === opt.value ) {
				return; // placeholder 永遠保留
			}
			opt.hidden = allowed ? ! allowed.has( opt.value ) : true;
			opt.disabled = opt.hidden;
		} );

		// 若目前 selected 不屬於新 state，重設成 placeholder
		if ( select.value && allowed && ! allowed.has( select.value ) ) {
			const patch = {};
			patch[ FIELD_ID ] = '';
			if ( moksafowo_tw_district.autofill !== false ) {
				patch.postcode = '';
			}
			setAddress( prefix, patch );
		}
	}

	/** 局部更新 store 裡的地址；延到下一輪，避免跟 React 正在處理的 onChange 互蓋。 */
	function setAddress( prefix, patch ) {
		setTimeout( function () {
			if ( ! window.wp || ! wp.data || ! wp.data.dispatch ) {
				return;
			}
			const cart = wp.data.dispatch( 'wc/store/cart' );
			const fn   = 'billing' === prefix ? cart && cart.setBillingAddress : cart && cart.setShippingAddress;
			if ( typeof fn === 'function' ) {
				fn( patch );
			}
		}, 0 );
	}

	/** district 變更 → 寫 postcode（設定頁可關） */
	function syncPostcode( prefix ) {
		if ( moksafowo_tw_district.autofill === false ) {
			return;
		}
		const select = getSelect( prefix );
		if ( ! select || ! select.value ) {
			return;
		}
		const postcode = POSTCODES[ select.value ];
		if ( ! postcode ) {
			return;
		}
		setAddress( prefix, { postcode: postcode } );
	}

	/** 給 moksafowo/district wrapper 加 class（CSS hook）。 */
	function tagWrapper( select ) {
		if ( ! select ) {
			return null;
		}
		// 找最接近 .wc-block-components-address-form 的直接子層
		let node = select.parentElement;
		while ( node && ! ( node.parentElement && node.parentElement.classList.contains( 'wc-block-components-address-form' ) ) ) {
			node = node.parentElement;
		}
		if ( node && ! node.classList.contains( 'moksafowo-tw-district-wrapper' ) ) {
			node.classList.add( 'moksafowo-tw-district-wrapper' );
		}
		return node;
	}

	/**
	 * Block additional fields 沒 priority/index API，CSS order 也不被 Block 使用。
	 * 直接 insertBefore 把 district wrapper 插到 state wrapper 後面（取代 city 的位置）。
	 */
	function repositionAfterState( prefix, districtWrapper ) {
		if ( ! districtWrapper ) {
			return;
		}
		const stateInput = document.getElementById( prefix + '-state' );
		if ( ! stateInput ) {
			return;
		}
		// 找 state 的同 form 子層 wrapper（`.wc-block-components-address-form__state`）
		let stateWrapper = stateInput.parentElement;
		while ( stateWrapper && ! ( stateWrapper.parentElement && stateWrapper.parentElement.classList.contains( 'wc-block-components-address-form' ) ) ) {
			stateWrapper = stateWrapper.parentElement;
		}
		if ( ! stateWrapper || stateWrapper.parentElement !== districtWrapper.parentElement ) {
			return;
		}
		// 已經在 state 後面就不動（避免無限 reflow）
		if ( districtWrapper.previousElementSibling === stateWrapper ) {
			return;
		}
		stateWrapper.parentElement.insertBefore( districtWrapper, stateWrapper.nextSibling );
	}

	function attachListeners() {
		[ 'shipping', 'billing' ].forEach( function ( prefix ) {
			const country = document.getElementById( prefix + '-country' );
			const state   = document.getElementById( prefix + '-state' );
			const select  = getSelect( prefix );

			const districtWrapper = tagWrapper( select );
			repositionAfterState( prefix, districtWrapper );

			if ( country && ! country.dataset.moTwDistrictBound ) {
				country.dataset.moTwDistrictBound = '1';
				country.addEventListener( 'change', function () {
					setTimeout( function () { filterOptions( prefix ); }, 50 );
				} );
			}
			if ( state && ! state.dataset.moTwDistrictBound ) {
				state.dataset.moTwDistrictBound = '1';
				state.addEventListener( 'change', function () {
					setTimeout( function () { filterOptions( prefix ); }, 50 );
				} );
			}
			if ( select && ! select.dataset.moTwDistrictBound ) {
				select.dataset.moTwDistrictBound = '1';
				select.addEventListener( 'change', function () {
					syncPostcode( prefix );
				} );
				filterOptions( prefix ); // 初始套用
			}
		} );
	}

	// React 重新 mount 時 attach listener；用 raf debounce
	let scheduled = null;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = window.requestAnimationFrame( function () {
			scheduled = null;
			attachListeners();
		} );
	}

	/**
	 * 訂閱 wc/store/cart — 顧客在 address form 改 country/state 時，
	 * Block 內部 state 更新（DOM 不一定 re-mount），用 wp.data 比 MutationObserver 即時。
	 * MutationObserver 仍保留處理 React re-mount input 的 case。
	 */
	function startStoreSubscribe() {
		if ( ! window.wp || ! window.wp.data || ! window.wp.data.subscribe ) {
			return; // 沒 wp.data 就靠 MutationObserver
		}
		const cartStore = window.wp.data.select( 'wc/store/cart' );
		if ( ! cartStore || typeof cartStore.getCartData !== 'function' ) {
			return;
		}
		let lastSig = '';
		window.wp.data.subscribe( function () {
			const cart = cartStore.getCartData();
			if ( ! cart ) {
				return;
			}
			const ship = cart.shippingAddress || {};
			const bill = cart.billingAddress  || {};
			const sig  = [ ship.country, ship.state, bill.country, bill.state ].join( '|' );
			if ( sig === lastSig ) {
				return;
			}
			lastSig = sig;
			attachListeners();
			filterOptions( 'shipping' );
			filterOptions( 'billing' );
		} );
	}

	/**
	 * 前端驗證防呆（drpen 11-17 回報、測試站未重現）：勾「使用相同的地址接收帳單」、畫面上已沒有
	 * 帳單欄位時，清掉殘留的 billing 錯誤。殘留錯誤指向不存在的欄位，按下單只會捲回上方、不送出也
	 * 沒有訊息。帳單由 WC 用收件地址帶入，伺服器照常驗證。
	 * （鄉鎮市區的錯誤不能這樣清：WC 按下單當下就驗證，錯誤擋下送出後才被清掉，變成按了沒反應。）
	 */
	const BILLING_ERROR = /^billing[_-]/;
	function clearStaleErrors() {
		const v = wp.data.select( 'wc/store/validation' );
		const errors = v && typeof v.getValidationErrors === 'function' ? v.getValidationErrors() : null;
		if ( ! errors ) {
			return;
		}
		const keys = Object.keys( errors );
		if ( ! keys.length ) {
			return;
		}
		const checkout  = wp.data.select( 'wc/store/checkout' );
		const sameAddr  = checkout && typeof checkout.getUseShippingAsBilling === 'function' && checkout.getUseShippingAsBilling();
		const noBilling = ! document.querySelector( '.wc-block-components-address-form [id^="billing-"]' );
		if ( ! sameAddr || ! noBilling ) {
			return;
		}
		const stale = keys.filter( function ( k ) {
			return BILLING_ERROR.test( k );
		} );
		if ( stale.length ) {
			wp.data.dispatch( 'wc/store/validation' ).clearValidationErrors( stale );
		}
	}

	function startValidationGuard( tries ) {
		tries = tries || 0;
		if ( ! window.wp || ! wp.data || ! wp.data.subscribe || ! wp.data.select( 'wc/store/validation' ) ) {
			// WC 的資料 store 可能比這支晚註冊，最多等 10 秒。
			if ( tries < 20 ) {
				setTimeout( function () { startValidationGuard( tries + 1 ); }, 500 );
			}
			return;
		}
		wp.data.subscribe( clearStaleErrors, 'wc/store/validation' );
		wp.data.subscribe( clearStaleErrors, 'wc/store/checkout' );
		clearStaleErrors();
	}

	function start() {
		attachListeners();
		startStoreSubscribe();
		startValidationGuard();
		const root = document.querySelector( '.wp-block-woocommerce-checkout, .wc-block-components-checkout-form' );
		if ( root ) {
			new MutationObserver( schedule ).observe( root, { childList: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
