/**
 * ECPay Shipping CVS 選店 — Classic + Block checkout 共用 JS。
 *
 * Flow:
 *   1. 選 CVS 物流 → 顯示「選擇取貨門市」host
 *   2. 點按鈕 → POST mo_ecpay_shipping_open_map → 拿到 ECPay form_data + api_url → submit form 開 ECPay 地圖
 *   3. ECPay 跳回 ?wc-api=mo_ecpay_shipping_map_callback → 我們 redirect 結帳頁 + ?mo_ecpay_store=<token>
 *   4. JS 偵測 token → POST mo_ecpay_shipping_resolve_token 還原 store → 寫入 session → render
 */
( function () {
	'use strict';
	if ( ! window.mo_ecpay_shipping ) {
		return;
	}
	const cfg = window.mo_ecpay_shipping;
	const HOST_ID = 'mo-ecpay-shipping-store-host';

	function isCvsMethod( methodId ) {
		if ( ! methodId ) {
			return false;
		}
		const id = String( methodId ).split( ':' )[ 0 ];
		return cfg.cvs_methods.indexOf( id ) !== -1;
	}

	function chosenShippingMethod() {
		// Classic
		const classic = document.querySelector( 'input[name^="shipping_method"]:checked' );
		if ( classic ) {
			return classic.value;
		}
		// Block
		const blockRadio = document.querySelector( '.wc-block-components-shipping-rates-control input[type="radio"]:checked' );
		if ( blockRadio && blockRadio.offsetParent !== null ) {
			return blockRadio.value;
		}
		return '';
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function findHost() {
		// Classic 已經在 review_order 渲染好 row
		const existing = document.getElementById( HOST_ID );
		if ( existing ) {
			return existing;
		}
		// Block — 動態插到 shipping-method-block 之後
		const anchor = document.querySelector( '.wp-block-woocommerce-checkout-shipping-method-block' );
		if ( ! anchor || ! anchor.parentNode ) {
			return null;
		}
		const host = document.createElement( 'div' );
		host.id = HOST_ID;
		host.className = 'mo-ecpay-shipping-store';
		anchor.parentNode.insertBefore( host, anchor.nextSibling );
		return host;
	}

	function showRow( show ) {
		const row = document.querySelector( '.mo-ecpay-shipping-store-row' );
		if ( row ) {
			row.style.display = show ? '' : 'none';
		}
	}

	function paint( store ) {
		const host = findHost();
		if ( ! host ) {
			return;
		}
		showRow( true );

		const btnLabel = store && store.id ? cfg.i18n.change : cfg.i18n.select;
		let body;
		if ( store && store.id ) {
			body =
				'<div class="mo-ecpay-shipping-store__info">' +
					'<strong>' + escapeHtml( store.name ) + '</strong> ' +
					'<span class="mo-ecpay-shipping-store__id">(' + escapeHtml( cfg.i18n.store_id ) + ' ' + escapeHtml( store.id ) + ')</span>' +
					'<div class="mo-ecpay-shipping-store__address">' + escapeHtml( store.address ) + '</div>' +
				'</div>';
		} else {
			body = '<span class="mo-ecpay-shipping-store__placeholder">' + escapeHtml( cfg.i18n.none_selected ) + '</span>';
		}
		host.innerHTML =
			body +
			'<button type="button" class="button mo-ecpay-shipping-store__btn">' + escapeHtml( btnLabel ) + '</button>';
		const btn = host.querySelector( '.mo-ecpay-shipping-store__btn' );
		if ( btn ) {
			btn.addEventListener( 'click', onSelect );
		}
	}

	function clear() {
		const host = document.getElementById( HOST_ID );
		if ( host ) {
			host.innerHTML = '';
			// Block-injected host 沒外層 <tr style="display:none"> 包住，innerHTML
			// 清空後仍是個可見的 div。直接 remove，不留殘骸。Classic 的 host 在
			// <tr> 內，<tr> 由 showRow 隱藏不需 remove。
			const tr = host.closest( 'tr.mo-ecpay-shipping-store-row' );
			if ( ! tr ) {
				host.remove();
			}
		}
		showRow( false );
	}

	function onSelect( e ) {
		e.preventDefault();
		const method = chosenShippingMethod();
		if ( ! isCvsMethod( method ) ) {
			return;
		}
		const fd = new FormData();
		fd.append( 'action', 'mo_ecpay_shipping_open_map' );
		fd.append( 'shipping_method', method );
		fd.append( 'nonce', cfg.nonce );
		// 帶當下頁面 URL，callback 後 redirect 回原頁（Block / Classic / 自訂頁皆可）
		fd.append( 'referrer', window.location.href );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				if ( ! resp || ! resp.success ) {
					alert( ( resp && resp.data && resp.data.message ) || cfg.i18n.error );
					return;
				}
				submitForm( resp.data.api_url, resp.data.form_data );
			} )
			.catch( function () { alert( cfg.i18n.error ); } );
	}

	function submitForm( apiUrl, formData ) {
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

	function readToken() {
		try {
			return new URL( window.location.href ).searchParams.get( cfg.token_query ) || '';
		} catch ( _ ) {
			return '';
		}
	}

	function cleanToken() {
		try {
			const url = new URL( window.location.href );
			url.searchParams.delete( cfg.token_query );
			window.history.replaceState( null, '', url.toString() );
		} catch ( _ ) { /* noop */ }
	}

	function fetchStore( cb ) {
		const token = readToken();
		if ( token ) {
			const fd = new FormData();
			fd.append( 'action', 'mo_ecpay_shipping_resolve_token' );
			fd.append( 'token', token );
			fd.append( 'nonce', cfg.nonce );
			fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( resp ) {
					cleanToken();
					if ( resp && resp.success && resp.data ) {
						cb( resp.data );
					} else {
						sessionLookup( cb );
					}
				} )
				.catch( function () { sessionLookup( cb ); } );
			return;
		}
		sessionLookup( cb );
	}

	function sessionLookup( cb ) {
		const fd = new FormData();
		fd.append( 'action', 'mo_ecpay_shipping_get_store' );
		fd.append( 'nonce', cfg.nonce );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( resp ) {
				cb( resp && resp.success && resp.data ? resp.data : null );
			} )
			.catch( function () { cb( null ); } );
	}

	let renderQueued = false;
	function render() {
		const method = chosenShippingMethod();
		if ( ! isCvsMethod( method ) ) {
			clear();
			return;
		}
		fetchStore( function ( store ) {
			paint( store && store.id ? store : null );
		} );
	}
	function scheduleRender() {
		if ( renderQueued ) {
			return;
		}
		renderQueued = true;
		setTimeout( function () {
			renderQueued = false;
			render();
		}, 50 );
	}

	function init() {
		// 初次渲染（含 token resolve）
		scheduleRender();
		setTimeout( render, 500 );
		setTimeout( render, 1500 );

		document.addEventListener( 'change', function ( e ) {
			const t = e.target;
			if ( ! t || ! t.matches ) {
				return;
			}
			if (
				t.matches( 'input[name^="shipping_method"]' ) ||
				t.matches( '.wc-block-components-shipping-rates-control input[type="radio"]' )
			) {
				scheduleRender();
			}
		} );
		// jQuery 事件（Classic）
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout updated_shipping_method', scheduleRender );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
