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
		const chosen   = window.moksafowoCvsStore.chosenMethod();
		const methodId = /^moksafowo_smilepay_shipping_/.test( chosen ) ? chosen.split( ':' )[ 0 ] : '';
		const emapUrl  = cfg.cvs_methods.indexOf( methodId ) !== -1 ? cfg.emap_urls[ methodId ] : '';
		if ( ! emapUrl ) {
			removeHost();
			return;
		}

		// 速買配每方式對應一家超商,故只一顆選店鈕(不像藍新要先選超商)。
		const picked    = cfg.selected_store || {};
		// 7-11 選的門市不能顯示在全家底下（反之亦然）。
		const store     = picked.id && ( ! picked.method || picked.method === methodId ) ? picked : null;
		const host      = M.ensureHost( HOST_ID );
		host.classList.add( 'moksafowo-smilepay-shipping-store' );
		// 內容沒變就不重畫：重畫會觸發下面的 MutationObserver，形成每 200ms 重建按鈕的迴圈。
		const sig = methodId + '|' + ( store ? store.id : '' );
		if ( host.getAttribute( 'data-sig' ) === sig && host.querySelector( '.moksafowo-cvs-store__btn' ) ) {
			return;
		}
		host.setAttribute( 'data-sig', sig );
		const rightHtml = '<button type="button" class="moksafowo-cvs-store__btn button">' + M.escapeHtml( store ? cfg.i18n.change : cfg.i18n.select ) + '</button>';
		host.innerHTML  = M.cardHtml( {
			store: store,
			storeIdLabel: cfg.i18n.store_id,
			noneText: cfg.i18n.none_selected,
			rightHtml: rightHtml,
		} );
		const btn = host.querySelector( '.moksafowo-cvs-store__btn' );
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
