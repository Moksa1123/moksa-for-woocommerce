( function ( $ ) {
	'use strict';
	if ( ! window.mo_smilepay_shipping ) {
		return;
	}
	const cfg = window.mo_smilepay_shipping;

	function injectButton() {
		// Block 結帳的 shipping radio name 是 radio-control-0 / -1 / -N 動態。
		// 直接找 value 開頭是 mo_smilepay_shipping_ 且 checked 的 radio。
		const $sel = $( 'input[type="radio"]:checked' ).filter( function () {
			return /^mo_smilepay_shipping_/.test( $( this ).val() || '' );
		} ).first();
		const chosenValue = $sel.val() || '';
		const methodId    = chosenValue.split( ':' )[ 0 ];
		const isSmile     = cfg.cvs_methods.indexOf( methodId ) !== -1;

		// 移除舊的
		$( '.mo-smilepay-shipping-store' ).remove();

		if ( ! isSmile ) {
			return;
		}
		const emapUrl = cfg.emap_urls[ methodId ];
		if ( ! emapUrl ) {
			return;
		}

		const sel = cfg.selected_store && cfg.selected_store.id
			? cfg.selected_store.name + '（' + cfg.selected_store.id + '）'
			: cfg.i18n.none_selected;
		const btnLabel = cfg.selected_store && cfg.selected_store.id ? cfg.i18n.change : cfg.i18n.select;

		const $box = $( '<div class="mo-smilepay-shipping-store" style="margin:8px 0;padding:10px;border:1px solid #c0c0c0;border-radius:4px;background:#f8f9fa;"></div>' );
		$box.append( $( '<div></div>' ).text( sel ).css( { 'font-size': '13px', 'margin-bottom': '6px' } ) );
		$box.append(
			$( '<button type="button" class="mo-smilepay-shipping-store__btn button"></button>' )
				.text( btnLabel )
				.on( 'click', function ( e ) {
					e.preventDefault();
					window.location.href = emapUrl;
				} )
		);

		// 注入到所選 shipping radio 旁
		const $row = $sel.closest( 'li, label, .wc-block-components-radio-control__option' );
		if ( $row.length ) {
			$row.append( $box );
		} else {
			$( '#mainform, .wc-block-checkout' ).first().prepend( $box );
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
