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
	const HOST_ID = 'mowp-payuni-block-store-host';

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

	/**
	 * Host 必須塞進 step 的 __content（運送 tab 同層），不能 append 在 fieldset
	 * 末尾跟 __content 同層 — 後者拿不到 step content padding，會左右各凸出
	 * ~25px 全寬跑版。
	 */
	function findStepContainer() {
		const block = document.querySelector( '.wp-block-woocommerce-checkout-shipping-method-block' );
		if ( ! block ) {
			return null;
		}
		return block.querySelector( '.wc-block-components-checkout-step__content' ) || block;
	}

	function ensureHost() {
		let host = document.getElementById( HOST_ID );
		if ( host ) {
			return host;
		}
		host = document.createElement( 'div' );
		host.id = HOST_ID;
		host.className = 'mowp-payuni-block-store';
		return host;
	}

	function placeHost() {
		const container = findStepContainer();
		if ( ! container ) {
			return null;
		}
		const host = ensureHost();
		// 已在正確位置（container 最末尾）就不重新 insert，避免 reflow loop
		if ( host.parentNode === container && container.lastElementChild === host ) {
			return host;
		}
		container.appendChild( host );
		return host;
	}

	function removeHost() {
		const host = document.getElementById( HOST_ID );
		if ( host && host.parentNode ) {
			host.parentNode.removeChild( host );
		}
	}

	function escapeHtml( s ) {
		return String( s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function btnHtml( label ) {
		return '<button type="button" class="mowp-payuni-block-store__btn button wp-element-button">' + escapeHtml( label ) + '</button>';
	}

	function paint( store ) {
		const host = placeHost();
		if ( ! host ) {
			return;
		}
		if ( store && store.id ) {
			host.classList.add( 'is-selected' );
			host.innerHTML =
				'<div>' +
				'<div class="mowp-payuni-block-store__name">' +
				'<div class="mowp-payuni-block-store__info">' +
				'<span class="mowp-payuni-block-store__title">' + escapeHtml( store.name || '' ) + '</span>' +
				'<span class="mowp-payuni-block-store__address">' + escapeHtml( store.address || '' ) + '</span>' +
				'<span class="mowp-payuni-block-store__meta">' +
				'<span class="mowp-payuni-block-store__id">' + escapeHtml( ( cfg.i18n.store_id_label || '' ) + ' ' + ( store.id || '' ) ) + '</span>' +
				'</span>' +
				'</div>' +
				'</div>' +
				btnHtml( cfg.i18n.change ) +
				'</div>';
		} else {
			host.classList.remove( 'is-selected' );
			host.innerHTML =
				'<div>' +
				'<span class="mowp-payuni-block-store__placeholder">' + escapeHtml( cfg.i18n.none ) + '</span>' +
				btnHtml( cfg.i18n.select ) +
				'</div>';
		}
		const btn = host.querySelector( '.mowp-payuni-block-store__btn' );
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
				submitToPayuni( resp.data.api_url, resp.data.form_data );
			} )
			.catch( function () { alert( cfg.i18n.error ); } );
	}

	function submitToPayuni( apiUrl, formData ) {
		const f = document.createElement( 'form' );
		f.method = 'POST';
		f.action = apiUrl;
		Object.keys( formData ).forEach( function ( k ) {
			const i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = k;
			i.value = formData[ k ];
			f.appendChild( i );
		} );
		document.body.appendChild( f );
		f.submit();
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

	function setBodyCvsFlag( on ) {
		document.body.classList.toggle( 'mowp-cvs-shipping-active', !! on );
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
			if ( e.target.closest( '.wp-block-woocommerce-checkout' ) ) {
				scheduleRender( 30 );
				setTimeout( render, 250 );
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
