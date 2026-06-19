/* global moksafowoCs */
( function () {
	'use strict';
	if ( typeof moksafowoCs === 'undefined' ) {
		return;
	}
	var C = moksafowoCs;
	var i = C.i18n || {};
	var TOKEN_KEY = 'moksafowo_cs_token';
	var ICON = '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#d4af37" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="M21 21l-4.3-4.3"></path></svg>';
	var bodyEl = null;
	var pollTimer = null;

	function el( tag, cls, text ) {
		var e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text !== undefined ) {
			e.textContent = text;
		}
		return e;
	}

	function token() {
		try {
			return window.sessionStorage.getItem( TOKEN_KEY ) || '';
		} catch ( e ) {
			return '';
		}
	}
	function setToken( t ) {
		try {
			window.sessionStorage.setItem( TOKEN_KEY, t );
		} catch ( e ) {}
	}
	function clearToken() {
		try {
			window.sessionStorage.removeItem( TOKEN_KEY );
		} catch ( e ) {}
	}

	function stopPoll() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function api( path, body, cb ) {
		fetch( C.rest + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: JSON.stringify( body )
		} ).then( function ( r ) {
			return r.json();
		} ).then( cb ).catch( function () {
			cb( { ok: false, message: '' } );
		} );
	}

	function row( label, value ) {
		var r = el( 'div', 'moksafowo-cs-row' );
		r.appendChild( el( 'span', 'moksafowo-cs-k', label ) );
		r.appendChild( el( 'span', 'moksafowo-cs-v', value ) );
		return r;
	}

	/* ---- 狀態 1：驗證表單 ---- */
	function showForm( errMsg ) {
		stopPoll();
		bodyEl.innerHTML = '';
		bodyEl.appendChild( el( 'p', 'moksafowo-cs-hint', i.hint ) );
		if ( errMsg ) {
			bodyEl.appendChild( el( 'p', 'moksafowo-cs-err', errMsg ) );
		}
		var f = el( 'form', 'moksafowo-cs-form' );
		var o = el( 'input', 'moksafowo-cs-input' );
		o.type = 'text';
		o.placeholder = i.order_label;
		o.setAttribute( 'inputmode', 'numeric' );
		var p = el( 'input', 'moksafowo-cs-input' );
		p.type = 'text';
		p.placeholder = i.phone_label;
		p.maxLength = 3;
		p.setAttribute( 'inputmode', 'numeric' );
		var btn = el( 'button', 'moksafowo-cs-btn', i.submit );
		btn.type = 'submit';
		f.appendChild( o );
		f.appendChild( p );
		f.appendChild( btn );
		f.addEventListener( 'submit', function ( ev ) {
			ev.preventDefault();
			btn.disabled = true;
			btn.textContent = i.querying;
			api( '/verify', { order: o.value, phone3: p.value }, function ( res ) {
				if ( res && res.ok ) {
					setToken( res.token );
					renderSummary( res.summary );
				} else {
					showForm( ( res && res.message ) || '' );
				}
			} );
		} );
		bodyEl.appendChild( f );
	}

	/* ---- 狀態 2：訂單摘要 ---- */
	function renderSummary( s ) {
		stopPoll();
		bodyEl.innerHTML = '';
		if ( ! s ) {
			showForm();
			return;
		}
		var card = el( 'div', 'moksafowo-cs-card' );
		card.appendChild( el( 'div', 'moksafowo-cs-ordno', '#' + s.number ) );
		card.appendChild( row( i.status, s.status ) );
		card.appendChild( row( i.total, s.total ) );
		if ( s.payment_method ) {
			card.appendChild( row( i.payment, s.payment_method + '（' + ( s.paid ? i.paid : i.unpaid ) + '）' ) );
		}
		if ( s.atm_code ) {
			card.appendChild( row( i.atm, s.atm_code ) );
		}
		if ( s.cvs_code ) {
			card.appendChild( row( i.cvs, s.cvs_code ) );
		}
		if ( s.shipping_method ) {
			card.appendChild( row( i.shipping, s.shipping_method ) );
		}
		if ( s.shipping_number ) {
			card.appendChild( row( i.ship_no, s.shipping_number ) );
		}
		if ( s.invoice_number ) {
			card.appendChild( row( i.invoice, s.invoice_number ) );
		}
		if ( s.items && s.items.length ) {
			card.appendChild( row( i.items, s.items.map( function ( it ) {
				return it.name + ' ×' + it.qty;
			} ).join( '、' ) ) );
		}
		bodyEl.appendChild( card );

		var actions = el( 'div', 'moksafowo-cs-actions' );
		var contact = el( 'button', 'moksafowo-cs-btn', i.contact );
		contact.addEventListener( 'click', openThread );
		var again = el( 'button', 'moksafowo-cs-btn moksafowo-cs-btn-2', i.again );
		again.addEventListener( 'click', function () {
			clearToken();
			showForm();
		} );
		actions.appendChild( contact );
		actions.appendChild( again );
		bodyEl.appendChild( actions );
	}

	/* ---- 狀態 3：留言對話（轉真人）---- */
	function renderMessages( wrap, list ) {
		wrap.innerHTML = '';
		if ( ! list || ! list.length ) {
			wrap.appendChild( el( 'p', 'moksafowo-cs-hint', i.no_msg ) );
			return;
		}
		list.forEach( function ( m ) {
			var cls = 'customer' === m.sender ? 'customer' : ( 'ai' === m.sender ? 'ai' : 'staff' );
			var label = 'staff' === m.sender ? i.staff : ( 'ai' === m.sender ? ( i.ai_label || 'AI' ) : i.you );
			var b = el( 'div', 'moksafowo-cs-msg ' + cls );
			b.appendChild( el( 'div', 'moksafowo-cs-meta', label + ' · ' + ( m.created_at || '' ) ) );
			b.appendChild( document.createTextNode( m.body || '' ) );
			wrap.appendChild( b );
		} );
		wrap.scrollTop = wrap.scrollHeight;
	}

	function openThread() {
		bodyEl.innerHTML = '';
		var back = el( 'span', 'moksafowo-cs-back', i.back );
		back.addEventListener( 'click', function () {
			stopPoll();
			api( '/order', { token: token() }, function ( r ) {
				if ( r && r.ok ) {
					renderSummary( r.summary );
				} else {
					clearToken();
					showForm();
				}
			} );
		} );
		bodyEl.appendChild( back );

		var msgs = el( 'div', 'moksafowo-cs-msgs' );
		bodyEl.appendChild( msgs );

		var compose = el( 'div', 'moksafowo-cs-compose' );
		var input = el( 'input', 'moksafowo-cs-input' );
		input.type = 'text';
		input.placeholder = i.msg_ph;
		var send = el( 'button', 'moksafowo-cs-send' );
		send.innerHTML = '➤';
		send.title = i.send;
		compose.appendChild( input );
		compose.appendChild( send );
		bodyEl.appendChild( compose );

		function refresh( scroll ) {
			api( '/messages', { token: token() }, function ( r ) {
				if ( r && r.ok ) {
					renderMessages( msgs, r.messages );
				}
			} );
		}
		function submit() {
			var v = ( input.value || '' ).trim();
			if ( ! v ) {
				return;
			}
			send.disabled = true;
			input.value = '';
			api( '/message', { token: token(), body: v }, function ( r ) {
				send.disabled = false;
				if ( r && r.ok ) {
					renderMessages( msgs, r.messages );
				} else if ( r && r.message ) {
					alert( r.message );
				}
				input.focus();
			} );
		}
		send.addEventListener( 'click', submit );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				submit();
			}
		} );

		refresh();
		stopPoll();
		pollTimer = window.setInterval( refresh, 6000 );
	}

	/* ---- 開關 ---- */
	function openPanel( panel ) {
		panel.classList.add( 'moksafowo-cs-open' );
		if ( token() ) {
			bodyEl.innerHTML = '';
			bodyEl.appendChild( el( 'p', 'moksafowo-cs-hint', i.querying ) );
			api( '/order', { token: token() }, function ( r ) {
				if ( r && r.ok ) {
					renderSummary( r.summary );
				} else {
					clearToken();
					showForm();
				}
			} );
		} else {
			showForm();
		}
	}

	function build() {
		var bubble = el( 'button', 'moksafowo-cs-bubble' );
		bubble.innerHTML = ICON + '<span></span>';
		bubble.querySelector( 'span' ).textContent = C.title || i.bubble;

		var panel = el( 'div', 'moksafowo-cs-panel' );
		var head = el( 'div', 'moksafowo-cs-head' );
		head.appendChild( el( 'span', 'moksafowo-cs-dot' ) );
		var b = el( 'b' );
		b.textContent = C.title || i.bubble;
		head.appendChild( b );
		var x = el( 'button', 'moksafowo-cs-x', '×' );
		x.setAttribute( 'aria-label', i.close );
		head.appendChild( x );
		panel.appendChild( head );

		bodyEl = el( 'div', 'moksafowo-cs-body' );
		panel.appendChild( bodyEl );

		bubble.addEventListener( 'click', function () {
			bubble.style.display = 'none';
			openPanel( panel );
		} );
		x.addEventListener( 'click', function () {
			stopPoll();
			panel.classList.remove( 'moksafowo-cs-open' );
			bubble.style.display = 'flex';
		} );

		var wrap = el( 'div', 'moksafowo-cs-widget' );
		wrap.appendChild( panel );
		wrap.appendChild( bubble );
		document.body.appendChild( wrap );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', build );
	} else {
		build();
	}
}() );
