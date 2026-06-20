( function ( $ ) {
	'use strict';
	if ( ! window.moksafowo_smilepay_shipping ) {
		return;
	}
	const cfg = window.moksafowo_smilepay_shipping;

	const HOST_ID = 'moksafowo-smilepay-shipping-store-host';
	const M = window.moksafowoCvsStore;

	function removeHost() {
		const h = document.getElementById( HOST_ID );
		if ( h && h.parentNode ) {
			h.parentNode.removeChild( h );
		}
	}

	function injectButton() {
		// Block 結帳的 shipping radio name 是 radio-control-0 / -1 / -N 動態。
		// 直接找 value 開頭是 moksafowo_smilepay_shipping_ 且 checked 的 radio。
		const $sel = $( 'input[type="radio"]:checked' ).filter( function () {
			return /^moksafowo_smilepay_shipping_/.test( $( this ).val() || '' );
		} ).first();
		const methodId = ( $sel.val() || '' ).split( ':' )[ 0 ];
		const emapUrl  = cfg.cvs_methods.indexOf( methodId ) !== -1 ? cfg.emap_urls[ methodId ] : '';
		if ( ! emapUrl ) {
			removeHost();
			return;
		}

		// 速買配每方式對應一家超商,故只一顆選店鈕(不像藍新要先選超商)。
		const store     = cfg.selected_store && cfg.selected_store.id ? cfg.selected_store : null;
		const host      = M.ensureHost( HOST_ID );
		host.classList.add( 'moksafowo-smilepay-shipping-store' );
		const rightHtml = '<button type="button" class="mowp-cvs-store__btn button">' + M.escapeHtml( store ? cfg.i18n.change : cfg.i18n.select ) + '</button>';
		host.innerHTML  = M.cardHtml( {
			store: store,
			storeIdLabel: cfg.i18n.store_id,
			noneText: cfg.i18n.none_selected,
			rightHtml: rightHtml,
		} );
		const btn = host.querySelector( '.mowp-cvs-store__btn' );
		if ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				e.stopPropagation();
				window.location.href = emapUrl;
			} );
		}
	}

	// Classic：jQuery checkout 事件；Block：原生 change 不一定冒泡到 document，
	// 故另掛 MutationObserver 監看 Block 結帳容器 React 重繪後補注入。
	$( document ).on( 'updated_checkout updated_shipping_method updated_cart_totals change', injectButton );
	$( injectButton );

	let scheduled = false;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		window.setTimeout( function () {
			scheduled = false;
			injectButton();
		}, 200 );
	}
	if ( window.MutationObserver ) {
		const root = document.querySelector( '.wc-block-checkout, form.checkout' ) || document.body;
		new MutationObserver( schedule ).observe( root, { childList: true, subtree: true } );
	}
} )( jQuery );
