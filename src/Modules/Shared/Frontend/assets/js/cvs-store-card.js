/**
 * 共用超商選店卡片 helper —— 各物流商(ECPay / NewebPay / SmilePay)結帳選店共用,
 * 把重複的 escapeHtml / step 容器定位 / host 建立 / 卡片 markup / 表單送出抽出來。
 * 視覺對齊 PAYUni(見 cvs-store.css)。各家只留 provider 專屬邏輯(AJAX endpoint /
 * emap URL / 超商品牌選擇等)。
 *
 * window.moksafowoCvsStore:
 *   escapeHtml(s)
 *   stepContainer()                       — 取結帳運送 step 的 __content
 *   ensureHost(id)                        — 建立 / 定位卡片 host(塞進 step content)
 *   cardHtml({store, storeIdLabel, noneText, rightHtml, belowHtml})
 *   submitForm(apiUrl, formData)          — 建隱藏表單 POST(開外部地圖)
 */
( function () {
	'use strict';

	function escapeHtml( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	// Host 必須塞進 step 的 __content(與運送方式同層內部),不能跟 step block 同層
	// 當兄弟節點 — 後者拿不到 step content 左右 padding,卡片會左右各凸出 ~25px 跑版。
	function stepContainer() {
		var block = document.querySelector( '.wp-block-woocommerce-checkout-shipping-method-block' );
		if ( block ) {
			return block.querySelector( '.wc-block-components-checkout-step__content' ) || block;
		}
		return document.querySelector( '#shipping_method, .shipping_method' ); // Classic fallback
	}

	function ensureHost( id ) {
		var host = document.getElementById( id );
		if ( ! host ) {
			host = document.createElement( 'div' );
			host.id = id;
		}
		if ( ( ' ' + host.className + ' ' ).indexOf( ' moksafowo-cvs-store ' ) === -1 ) {
			host.className = ( 'moksafowo-cvs-store ' + host.className ).trim();
		}
		var c = stepContainer();
		if ( c ) {
			if ( host.parentNode !== c || c.lastElementChild !== host ) {
				c.appendChild( host );
			}
		} else if ( ! host.parentNode ) {
			document.body.appendChild( host );
		}
		return host;
	}

	function cardHtml( opts ) {
		opts = opts || {};
		var store = opts.store;
		var info;
		if ( store && store.id ) {
			info = '<div class="moksafowo-cvs-store__info">'
				+ '<span class="moksafowo-cvs-store__title">' + escapeHtml( store.name ) + '</span>'
				+ ( store.address ? '<span class="moksafowo-cvs-store__address">' + escapeHtml( store.address ) + '</span>' : '' )
				+ '<span class="moksafowo-cvs-store__meta"><span class="moksafowo-cvs-store__id">'
				+ escapeHtml( ( ( opts.storeIdLabel || '' ) + ' ' + store.id ).trim() )
				+ '</span></span>'
				+ '</div>';
		} else {
			info = '<span class="moksafowo-cvs-store__placeholder">' + escapeHtml( opts.noneText || '' ) + '</span>';
		}
		return '<div class="moksafowo-cvs-store__row">' + info + ( opts.rightHtml || '' ) + '</div>' + ( opts.belowHtml || '' );
	}

	function submitForm( apiUrl, formData ) {
		var f = document.createElement( 'form' );
		f.method = 'POST';
		f.action = apiUrl;
		Object.keys( formData || {} ).forEach( function ( k ) {
			var i = document.createElement( 'input' );
			i.type = 'hidden';
			i.name = k;
			i.value = formData[ k ];
			f.appendChild( i );
		} );
		document.body.appendChild( f );
		f.submit();
	}

	window.moksafowoCvsStore = {
		escapeHtml: escapeHtml,
		stepContainer: stepContainer,
		ensureHost: ensureHost,
		cardHtml: cardHtml,
		submitForm: submitForm,
	};
}() );
