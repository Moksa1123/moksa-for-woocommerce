/**
 * Block Checkout — PAYUNi CVS 選店 host。
 *
 * 不用 MutationObserver — 會跟 React reconciliation 互踩，把「運送 / 取貨」tab 弄壞。
 * Host 注到 .wp-block-woocommerce-checkout-shipping-method-block 的 next sibling，
 * 在 React 樹外面，不會被 reconcile 掉。
 */
( function () {
	'use strict';

	if ( ! window.moksafowo_payuni_block ) {
		return;
	}
	const cfg = window.moksafowo_payuni_block;
	const HOST_ID = 'moksafowo-payuni-block-store-host';

	function isCvs( methodId ) {
		return typeof methodId === 'string' && methodId.indexOf( cfg.cvs_method_prefix ) !== -1;
	}

	function isOnPickupTab() {
		const tabRadio = document.querySelector( 'input[name="shipping_method"]:checked' );
		if ( tabRadio ) {
			const v = String( tabRadio.value || '' ).toLowerCase();
			if ( v.indexOf( 'pickup' ) !== -1 ) {
				return true;
			}
		}
		if ( document.querySelector( 'input[type="radio"][name="pickup_location"]:checked' ) ) {
			return true;
		}
		const tabBtns = document.querySelectorAll(
			'.wc-block-checkout__shipping-method button[aria-pressed]'
		);
		for ( let i = 0; i < tabBtns.length; i++ ) {
			const btn = tabBtns[ i ];
			if ( btn.getAttribute( 'aria-pressed' ) === 'true' ) {
				const txt = ( btn.textContent || '' ).toLowerCase();
				if ( txt.indexOf( 'pickup' ) !== -1 || txt.indexOf( '取貨' ) !== -1 ) {
					return true;
				}
			}
		}
		return false;
	}

	function getCheckedRadio() {
		if ( isOnPickupTab() ) {
			return null;
		}
		// React 不卸載隱藏區塊，:checked 還在但 offsetParent 為 null
		const radios = document.querySelectorAll(
			'.wc-block-components-shipping-rates-control input[type="radio"]:checked'
		);
		for ( let i = 0; i < radios.length; i++ ) {
			if ( radios[ i ].offsetParent !== null ) {
				return radios[ i ];
			}
		}
		return null;
	}

	// host 建立 / step content 定位 / escapeHtml / submitForm 全走共用 moksafowoCvsStore helper。
	const M = window.moksafowoCvsStore;

	function removeHost() {
		const host = document.getElementById( HOST_ID );
		if ( host && host.parentNode ) {
			host.parentNode.removeChild( host );
		}
	}

	function paint( store ) {
		const host = M.ensureHost( HOST_ID );
		if ( ! host ) {
			return;
		}
		host.classList.toggle( 'is-selected', !! ( store && store.id ) );
		// 內容沒變就不重建：顧客正要按「選擇門市」時按鈕被換掉，點擊會落空。
		const sig = store && store.id ? String( store.id ) : '-';
		if ( host.getAttribute( 'data-sig' ) === sig && host.querySelector( '.moksafowo-cvs-store__btn' ) ) {
			return;
		}
		host.setAttribute( 'data-sig', sig );
		const rightHtml = '<button type="button" class="moksafowo-cvs-store__btn button wp-element-button">'
			+ M.escapeHtml( store && store.id ? cfg.i18n.change : cfg.i18n.select ) + '</button>';
		host.innerHTML = M.cardHtml( {
			store: store,
			storeIdLabel: cfg.i18n.store_id_label,
			noneText: cfg.i18n.none,
			rightHtml: rightHtml,
		} );
		const btn = host.querySelector( '.moksafowo-cvs-store__btn' );
		if ( btn ) {
			btn.addEventListener( 'click', onOpenMap );
		}
	}

	function onOpenMap( e ) {
		e.preventDefault();
		const checked = getCheckedRadio();
		if ( ! checked ) {
			return;
		}
		const fd = new FormData();
		fd.append( 'action', 'moksafowo_payuni_open_store_map' );
		fd.append( 'shipping_method', checked.value );
		fd.append( 'nonce', cfg.search_nonce );

		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					alert( ( resp && resp.data && resp.data.message ) || cfg.i18n.error );
					return;
				}
				M.submitForm( resp.data.api_url, resp.data.form_data );
			} )
			.catch( function () { alert( cfg.i18n.error ); } );
	}

	function readUrlToken() {
		try {
			return new URL( window.location.href ).searchParams.get( 'moksafowo_store' ) || '';
		} catch ( _ ) {
			return '';
		}
	}

	function cleanUrlToken() {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'moksafowo_store' );
			window.history.replaceState( null, '', url.toString() );
		} catch ( _ ) { /* noop */ }
	}

	function fetchSelectedStore( cb ) {
		// PAYUNi callback session 會掉，token 走 transient 跨站接回來
		const token = readUrlToken();
		if ( token ) {
			const fd = new FormData();
			fd.append( 'action', 'moksafowo_payuni_resolve_store_token' );
			fd.append( 'token', token );
			fd.append( 'nonce', cfg.search_nonce );
			fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					cleanUrlToken();
					if ( resp && resp.success && resp.data && resp.data.id ) {
						cb( resp.data );
						return;
					}
					sessionLookup( cb );
				} )
				.catch( function () { sessionLookup( cb ); } );
			return;
		}
		sessionLookup( cb );
	}

	function sessionLookup( cb ) {
		const fd = new FormData();
		fd.append( 'action', 'moksafowo_payuni_get_store_data' );
		fd.append( 'nonce', cfg.search_nonce );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				cb( resp && resp.success && resp.data && resp.data.id ? resp.data : null );
			} )
			.catch( function () { cb( null ); } );
	}

	// 超商時地址欄只用 CSS 藏起來；結帳頁上它們仍是必填（頁面 locale 不放寬，切回宅配時標籤
	// 才會正確），所以被藏起來那幾欄的前端驗證錯誤在這裡清掉。姓名電話照常驗證。
	const HIDDEN_ADDRESS_ERROR = /^(shipping|billing)[_-](country|state|city|postcode|address_1|address_2|company)$/;
	let cvsActive = false;

	function clearHiddenAddressErrors() {
		if ( ! cvsActive || ! window.wp || ! wp.data ) {
			return;
		}
		try {
			const store  = 'wc/store/validation';
			const errors = wp.data.select( store ).getValidationErrors();
			const keys   = Object.keys( errors || {} ).filter( function ( k ) { return HIDDEN_ADDRESS_ERROR.test( k ); } );
			if ( keys.length ) {
				wp.data.dispatch( store ).clearValidationErrors( keys );
			}
		} catch ( e ) {} // WC 的驗證 store 還沒註冊。
	}

	if ( window.wp && wp.data && wp.data.subscribe ) {
		wp.data.subscribe( clearHiddenAddressErrors, 'wc/store/validation' );
	}

	function setBodyCvsFlag( on ) {
		cvsActive = !! on;
		document.body.classList.toggle( 'moksafowo-cvs-shipping-active', cvsActive );
		clearHiddenAddressErrors();
	}

	let renderRequestId = 0;
	function render() {
		const checked = getCheckedRadio();
		if ( ! checked || ! isCvs( checked.value ) ) {
			removeHost();
			setBodyCvsFlag( false );
			renderRequestId++;
			return;
		}
		setBodyCvsFlag( true );
		const myId = ++renderRequestId;
		fetchSelectedStore( function ( store ) {
			if ( myId !== renderRequestId ) {
				return;
			}
			const c = getCheckedRadio();
			if ( ! c || ! isCvs( c.value ) ) {
				removeHost();
				setBodyCvsFlag( false );
				return;
			}
			paint( store );
		} );
	}

	let renderQueued = false;
	function scheduleRender( delay ) {
		if ( renderQueued ) {
			return;
		}
		renderQueued = true;
		setTimeout( function () {
			renderQueued = false;
			render();
		}, delay || 30 );
	}

	function init() {
		let tries = 0;
		const seekInterval = setInterval( function () {
			tries++;
			if ( getCheckedRadio() || tries >= 16 ) {
				clearInterval( seekInterval );
				render();
			}
		}, 500 );

		document.addEventListener( 'change', function ( e ) {
			const t = e.target;
			if ( ! t || ! t.matches ) {
				return;
			}
			if (
				t.matches( '.wc-block-components-shipping-rates-control input[type="radio"]' ) ||
				t.matches( 'input[name="shipping_method"]' ) ||
				t.matches( 'input[name="pickup_location"]' )
			) {
				scheduleRender( 30 );
			}
		} );

		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target || ! e.target.closest ) {
				return;
			}
			if ( e.target.closest( '#' + HOST_ID ) ) {
				return;
			}
			// 只有點到「運送／取貨」切換或運費選項時才重畫；原本結帳區任何點擊都排三次重畫，
			// 每次都打一個 admin-ajax。
			if ( e.target.closest( '.wc-block-checkout__shipping-method-option, .wc-block-components-shipping-rates-control' ) ) {
				scheduleRender( 30 );
				setTimeout( render, 600 );
			}
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
