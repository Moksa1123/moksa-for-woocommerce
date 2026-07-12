( function () {
	'use strict';

	if ( ! window.moksafowo_newebpay_shipping ) return;
	const cfg = window.moksafowo_newebpay_shipping;
	const HOST_ID = 'moksafowo-newebpay-shipping-store-host';

	function chosenShippingMethod() {
		const block = document.querySelector( 'input[type="radio"][name^="radio-control-"]:checked' );
		if ( block && /^moksafowo_newebpay_shipping_/.test( block.value || '' ) ) {
			return ( block.value.split( ':' )[0] || '' );
		}
		const classic = document.querySelector( 'input[name^="shipping_method"]:checked' );
		if ( classic ) {
			const v = String( classic.value || '' );
			if ( /^moksafowo_newebpay_shipping_/.test( v ) ) return v.split( ':' )[0];
		}
		return '';
	}

	function isNewebPayCvs( methodId ) {
		return cfg.cvs_methods.indexOf( methodId ) !== -1;
	}

	const M = window.moksafowoCvsStore;

	function carriers() {
		return ( cfg.carriers && cfg.carriers.length ) ? cfg.carriers : [ { ship_type: '1', name: '7-ELEVEN' } ];
	}

	// 藍新一個 CVS 方式涵蓋四大超商,靠 storeMap 的 ShipType 決定開哪家的電子地圖
	// (每家各自的圖、地圖內不能切換),所以開圖前讓顧客先選超商。
	function carrierButtonsHtml( btnPrefix ) {
		const esc = M.escapeHtml;
		const list = carriers();
		if ( list.length === 1 ) {
			const c = list[ 0 ];
			return `<div class="moksafowo-cvs-store__carriers"><button type="button" class="moksafowo-cvs-store__btn button" data-ship-type="${ esc( c.ship_type ) }">${ esc( btnPrefix ) }（${ esc( c.name ) }）</button></div>`;
		}
		const tip = `<div class="moksafowo-cvs-store__carriers-tip">${ esc( cfg.i18n.pick_carrier ) }</div>`;
		const btns = list.map( ( c ) => `<button type="button" class="moksafowo-cvs-store__carrier button" data-ship-type="${ esc( c.ship_type ) }">${ esc( c.name ) }</button>` ).join( '' );
		return `<div class="moksafowo-cvs-store__carriers">${ tip }<div>${ btns }</div></div>`;
	}

	function renderHost( store ) {
		const host = M.ensureHost( HOST_ID );
		host.innerHTML = M.cardHtml( {
			store: store,
			storeIdLabel: cfg.i18n.store_id,
			noneText: cfg.i18n.none_selected,
			belowHtml: carrierButtonsHtml( store ? cfg.i18n.change : cfg.i18n.select ),
		} );
	}

	function clearHost() {
		const host = document.getElementById( HOST_ID );
		if ( host && host.parentNode ) host.parentNode.removeChild( host );
	}

	function refresh() {
		const method = chosenShippingMethod();
		if ( ! isNewebPayCvs( method ) ) {
			clearHost();
			return;
		}
		// Read store from session via AJAX (or local var)
		renderHost( window.__moksafowoNewebpayStore || null );
	}

	function openMap( shipType ) {
		const fd = new FormData();
		fd.append( 'action', 'moksafowo_newebpay_shipping_open_map' );
		fd.append( 'shipping_method', chosenShippingMethod() );
		fd.append( 'ship_type', shipType || '1' );
		fd.append( 'referrer', window.location.href );
		fd.append( 'nonce', cfg.nonce );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( res.success && res.data && res.data.api_url ) {
					M.submitForm( res.data.api_url, res.data.form_data );
				} else {
					alert( res.data?.message || cfg.i18n.error );
				}
			} )
			.catch( () => alert( cfg.i18n.error ) );
	}

	function onClick( e ) {
		const t = e.target;
		if ( t && t.tagName === 'BUTTON' && t.hasAttribute( 'data-ship-type' ) && t.closest( '#' + HOST_ID ) ) {
			e.preventDefault();
			openMap( t.getAttribute( 'data-ship-type' ) || '1' );
		}
	}

	function resolveToken() {
		const t = new URL( window.location.href ).searchParams.get( cfg.token_query );
		if ( ! t ) return;
		const fd = new FormData();
		fd.append( 'action', 'moksafowo_newebpay_shipping_resolve_token' );
		fd.append( 'token', t );
		fd.append( 'nonce', cfg.nonce );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( res.success ) {
					window.__moksafowoNewebpayStore = res.data;
					// 清掉 query string
					const u = new URL( window.location.href );
					u.searchParams.delete( cfg.token_query );
					history.replaceState( null, '', u.toString() );
					refresh();
				}
			} );
	}

	document.addEventListener( 'click', onClick );
	document.addEventListener( 'change', ( e ) => {
		const t = e.target;
		if ( t && (
			t.matches( 'input[name^="shipping_method"]' )
			|| t.matches( 'input[type="radio"][name^="radio-control-"]' )
		) ) {
			setTimeout( refresh, 200 );
		}
	} );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', () => { resolveToken(); refresh(); } );
	} else {
		resolveToken();
		refresh();
	}
}() );
