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
		// 這一步會整頁導離結帳頁，回來是全新載入 —— 先把顧客填好的欄位存起來，
		// 否則姓名電話地址全空。四家物流商都經過這裡，所以接在這裡就一次覆蓋。
		if ( window.moksafowoCvsFields && window.moksafowoCvsFields.save ) {
			window.moksafowoCvsFields.save();
		}
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

	/**
	 * 目前選的運送方式 ID（含 instance，例如 foo:3）；找不到回空字串。
	 * 只有一種運送方式時 WC 不印 radio：傳統結帳是 hidden input，區塊結帳要從 store 讀。
	 */
	function chosenMethod() {
		const radios = document.querySelectorAll( '.wc-block-components-shipping-rates-control input[type="radio"]:checked, input[type="radio"][name^="radio-control-"]:checked' );
		for ( let i = 0; i < radios.length; i++ ) {
			// 切到「取貨」分頁時運費選項被藏起來，但 radio 仍是 :checked，要排除看不見的。
			if ( radios[ i ].offsetParent !== null ) {
				return String( radios[ i ].value || '' );
			}
		}
		const classic = document.querySelector( 'input[name^="shipping_method"]:checked' );
		if ( classic ) {
			return String( classic.value || '' );
		}
		const single = document.querySelector( 'input[type="hidden"][name^="shipping_method"]' );
		if ( single ) {
			return String( single.value || '' );
		}
		try {
			const packages = window.wp && window.wp.data ? window.wp.data.select( 'wc/store/cart' ).getShippingRates() : [];
			for ( let p = 0; p < packages.length; p++ ) {
				const rates = packages[ p ].shipping_rates || [];
				for ( let r = 0; r < rates.length; r++ ) {
					if ( rates[ r ].selected ) {
						return String( rates[ r ].rate_id || '' );
					}
				}
			}
		} catch ( e ) {}
		return '';
	}

	window.moksafowoCvsStore = {
		chosenMethod: chosenMethod,
		escapeHtml: escapeHtml,
		stepContainer: stepContainer,
		ensureHost: ensureHost,
		cardHtml: cardHtml,
		submitForm: submitForm,
	};
}() );
