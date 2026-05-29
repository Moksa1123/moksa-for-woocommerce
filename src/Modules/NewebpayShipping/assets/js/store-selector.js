( function () {
	'use strict';

	if ( ! window.mo_newebpay_shipping ) return;
	const cfg = window.mo_newebpay_shipping;
	const HOST_ID = 'mo-newebpay-shipping-store-host';

	function chosenShippingMethod() {
		const block = document.querySelector( 'input[type="radio"][name^="radio-control-"]:checked' );
		if ( block && /^mo_newebpay_shipping_/.test( block.value || '' ) ) {
			return ( block.value.split( ':' )[0] || '' );
		}
		const classic = document.querySelector( 'input[name^="shipping_method"]:checked' );
		if ( classic ) {
			const v = String( classic.value || '' );
			if ( /^mo_newebpay_shipping_/.test( v ) ) return v.split( ':' )[0];
		}
		return '';
	}

	function isNewebPayCvs( methodId ) {
		return cfg.cvs_methods.indexOf( methodId ) !== -1;
	}

	function ensureHost() {
		let host = document.getElementById( HOST_ID );
		if ( host ) return host;
		host = document.createElement( 'div' );
		host.id = HOST_ID;
		host.className = 'mo-newebpay-shipping-store';
		host.style.cssText = 'margin: 12px 0; padding: 12px; border: 1px solid #ddd; border-radius: 4px; background: #f9f9f9;';
		// Find shipping options container to insert after
		const shippingBlock = document.querySelector( '.wp-block-woocommerce-checkout-shipping-method-block, #shipping_method, .shipping_method' );
		if ( shippingBlock && shippingBlock.parentNode ) {
			shippingBlock.parentNode.insertBefore( host, shippingBlock.nextSibling );
		} else {
			document.body.appendChild( host );
		}
		return host;
	}

	function renderHost( store ) {
		const host = ensureHost();
		const label = store ? cfg.i18n.change : cfg.i18n.select;
		const info = store
			? `<div><strong>${ escapeHtml( store.name || '' ) }</strong> (${ escapeHtml( cfg.i18n.store_id ) }: ${ escapeHtml( store.id || '' ) })<br><small>${ escapeHtml( store.address || '' ) }</small></div>`
			: `<div style="color:#666;">${ escapeHtml( cfg.i18n.none_selected ) }</div>`;
		host.innerHTML = info + `<button type="button" class="mo-newebpay-shipping-store__btn button" style="margin-top:8px;">${ escapeHtml( label ) }</button>`;
	}

	function clearHost() {
		const host = document.getElementById( HOST_ID );
		if ( host && host.parentNode ) host.parentNode.removeChild( host );
	}

	function escapeHtml( s ) {
		return String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );
	}

	function refresh() {
		const method = chosenShippingMethod();
		if ( ! isNewebPayCvs( method ) ) {
			clearHost();
			return;
		}
		// Read store from session via AJAX (or local var)
		renderHost( window.__moNewebpayStore || null );
	}

	function onClick( e ) {
		const t = e.target;
		if ( t && t.classList && t.classList.contains( 'mo-newebpay-shipping-store__btn' ) ) {
			e.preventDefault();
			const fd = new FormData();
			fd.append( 'action', 'mo_newebpay_shipping_open_map' );
			fd.append( 'shipping_method', chosenShippingMethod() );
			fd.append( 'ship_type', '1' ); // default 7-11；TODO: per-method ship_type
			fd.append( 'referrer', window.location.href );
			fd.append( 'nonce', cfg.nonce );
			fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
				.then( ( r ) => r.json() )
				.then( ( res ) => {
					if ( res.success && res.data && res.data.api_url ) {
						submitForm( res.data.api_url, res.data.form_data );
					} else {
						alert( res.data?.message || cfg.i18n.error );
					}
				} )
				.catch( () => alert( cfg.i18n.error ) );
		}
	}

	function submitForm( url, fields ) {
		const f = document.createElement( 'form' );
		f.method = 'POST';
		f.action = url;
		Object.keys( fields ).forEach( ( k ) => {
			const i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = k;
			i.value = fields[ k ];
			f.appendChild( i );
		} );
		document.body.appendChild( f );
		f.submit();
	}

	function resolveToken() {
		const t = new URL( window.location.href ).searchParams.get( cfg.token_query );
		if ( ! t ) return;
		const fd = new FormData();
		fd.append( 'action', 'mo_newebpay_shipping_resolve_token' );
		fd.append( 'token', t );
		fd.append( 'nonce', cfg.nonce );
		fetch( cfg.ajax_url, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( ( r ) => r.json() )
			.then( ( res ) => {
				if ( res.success ) {
					window.__moNewebpayStore = res.data;
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
