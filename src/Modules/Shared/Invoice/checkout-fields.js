( function () {
	'use strict';

	// Block 結帳：欄位顯示 / 隱藏 / 必填 / 載具編號 label 全由 WC 原生 JSON Schema 條件式處理
	// （register_additional_checkout_field 的 hidden / required / validation）。本檔在 Block 端只做
	// schema 無法宣告式表達的：預設值帶入、捐贈碼自動填、section 標題改名。
	// Classic 結帳沒有 JSON Schema，欄位顯示靠 classicVisibility() 依下拉 show/hide（純 DOM，不涉 React）。

	function findField( namePart ) {
		const dashed    = namePart.replace( /_/g, '-' );
		const selectors = [
			'[name="moksafowo_' + namePart + '"]', // classic
			'[id$="-moksafowo-' + dashed + '"]',        // block（location group prefix）
			'[name$="-moksafowo-' + dashed + '"]',
			'[name*="moksafowo/' + dashed + '"]',
		];
		for ( let i = 0; i < selectors.length; i++ ) {
			const el = document.querySelector( selectors[ i ] );
			if ( el ) {
				return el;
			}
		}
		return null;
	}

	function setVal( el, val ) {
		const proto = 'SELECT' === el.tagName
			? HTMLSelectElement.prototype
			: ( 'TEXTAREA' === el.tagName ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype );
		Object.getOwnPropertyDescriptor( proto, 'value' ).set.call( el, val );
		el.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		el.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	/**
	 * Block 不吃 register_additional_checkout_field 的 default — 空值送出會被 required schema
	 * 擋下「請選取有效的選項」。預選發票類型=個人；載具類型=第一個可用選項
	 * （載具類型在非個人時被 schema 自動隱藏，這裡只在它存在且為空時帶入）。
	 */
	function preselect() {
		const typeSel = findField( 'invoice_type' );
		if ( typeSel && '' === typeSel.value ) {
			setVal( typeSel, 'b2c_carrier' );
		}
		const carrierSel = findField( 'invoice_carrier_type' );
		if ( carrierSel && '' === carrierSel.value ) {
			const first = Array.prototype.find.call( carrierSel.options, function ( o ) {
				return '' !== o.value;
			} );
			if ( first ) {
				setVal( carrierSel, first.value );
			}
		}
	}

	/**
	 * 捐贈：有「捐贈單位」下拉（商家設定了清單）→ 愛心碼欄唯讀，自動帶入選到單位的碼。
	 * 沒有下拉（商家沒設定）→ 愛心碼欄開放自填。
	 */
	function syncDonate() {
		const orgSel    = findField( 'invoice_donate_org' );
		const loveInput = findField( 'invoice_love_code' );
		if ( ! loveInput ) {
			return;
		}
		if ( ! orgSel ) {
			loveInput.readOnly = false;
			return;
		}
		loveInput.readOnly = true;
		const code = orgSel.value || '';
		if ( loveInput.value !== code ) {
			setVal( loveInput, code );
		}
	}

	/**
	 * Block「其他訂單資訊」(WC core 對 location='order' fields 的預設標題) 改成「電子發票」。
	 */
	function renameHeading() {
		const headings = document.querySelectorAll( '.wp-block-woocommerce-checkout-additional-information-block h2, .wp-block-woocommerce-checkout-additional-information-block .wc-block-components-checkout-step__title' );
		headings.forEach( function ( h ) {
			const txt = ( h.innerText || '' ).trim();
			if ( '其他訂單資訊' === txt || 'Additional order information' === txt ) {
				h.innerText = '電子發票';
			}
		} );
	}

	/**
	 * Classic 結帳的條件顯示 —— 只作用在 classic 命名欄位（[name="moksafowo_invoice_*"]）。
	 * Block 欄位用不同 name（moksafowo/...），不會被這裡選到，維持由 JSON Schema 控制。
	 */
	function classicRow( namePart ) {
		const el = document.querySelector( '[name="moksafowo_' + namePart + '"]' );
		return el ? ( el.closest( '.form-row, p' ) || el.parentElement ) : null;
	}

	function showRow( row, visible ) {
		if ( row ) {
			row.style.display = visible ? '' : 'none';
		}
	}

	/**
	 * 借用頁面上既有的必填 / 選填標記當範本，而不是自己拼字串 —— 那兩個標記的文案
	 * （「*」的 title、「(選填)」）是 WooCommerce 核心自己的翻譯，猜錯就會混進英文。
	 * 發票類型一定是必填、且結帳頁一定有選填欄位，所以兩個範本都取得到。
	 */
	const markers = { required: null, optional: null };
	function markerTemplates() {
		// 只在「真的抓到」時才存起來。Classic 結帳會 AJAX 重繪整張表單，第一次 tick()
		// 可能早於表單存在；若把當下的 null 快取住，之後就永遠補不上標記了。
		if ( ! markers.required ) {
			// 用 class 選、不綁標籤名：WooCommerce 舊版是 <abbr class="required">，
			// 目前版本是 <span class="required" aria-hidden="true">，寫死其一就會抓不到。
			const req = document.querySelector( '.form-row label .required' );
			if ( req ) {
				markers.required = req.cloneNode( true );
			}
		}
		if ( ! markers.optional ) {
			const opt = document.querySelector( '.form-row label .optional' );
			if ( opt ) {
				markers.optional = opt.cloneNode( true );
			}
		}
		return markers;
	}

	/**
	 * Classic 沒有 JSON Schema，required 只能在這裡跟著顯示條件一起切。
	 *
	 * 刻意**不動原生 required 屬性**：被 display:none 藏起來的欄位若帶 required，
	 * 瀏覽器會以「An invalid form control is not focusable」擋下整張表單且不給提示，
	 * 商家會看到按了沒反應。這裡只切 WooCommerce 自己認得的 validate-required class
	 * 與 label 上的視覺標記，真正的守門仍在 server 端的 after_checkout_validation。
	 */
	function setRequired( row, required ) {
		if ( ! row ) {
			return;
		}
		row.classList.toggle( 'validate-required', required );

		const label = row.querySelector( 'label' );
		if ( ! label ) {
			return;
		}
		const tpl     = markerTemplates();
		const reqNode = label.querySelector( '.required' );
		const optNode = label.querySelector( '.optional' );

		if ( required ) {
			if ( optNode ) {
				optNode.remove();
			}
			if ( ! reqNode && tpl.required ) {
				label.appendChild( document.createTextNode( ' ' ) );
				label.appendChild( tpl.required.cloneNode( true ) );
			}
			return;
		}
		if ( reqNode ) {
			reqNode.remove();
		}
		if ( ! optNode && tpl.optional ) {
			label.appendChild( document.createTextNode( ' ' ) );
			label.appendChild( tpl.optional.cloneNode( true ) );
		}
	}

	function classicVisibility() {
		const typeSel = document.querySelector( '[name="moksafowo_invoice_type"]' );
		if ( ! typeSel ) {
			return; // 非 classic（Block 走 JSON Schema）
		}
		const carrierSel = document.querySelector( '[name="moksafowo_invoice_carrier_type"]' );
		const type       = typeSel.value || 'b2c_carrier';
		const carrier    = carrierSel ? carrierSel.value : '';
		const isCarrier  = 'b2c_carrier' === type;
		const needNum    = isCarrier && ( 'mobile' === carrier || 'cert' === carrier );
		const isB2b      = 'b2b' === type;
		const isDonate   = 'b2c_donate' === type;

		// 條件與必填一一對應，數值與 Block 的 JSON Schema 相同（見 InvoiceCheckoutFields）。
		// 例外只有捐贈單位：它是帶入捐贈碼用的便利下拉，Block 那邊也是 required=false。
		const rows = [
			[ 'invoice_carrier_type', isCarrier, isCarrier ],
			[ 'invoice_carrier_num', needNum, needNum ],
			[ 'invoice_buyer_ubn', isB2b, isB2b ],
			[ 'invoice_buyer_name', isB2b, isB2b ],
			[ 'invoice_donate_org', isDonate, false ],
			[ 'invoice_love_code', isDonate, isDonate ],
		];
		rows.forEach( function ( item ) {
			const row = classicRow( item[ 0 ] );
			showRow( row, item[ 1 ] );
			setRequired( row, item[ 2 ] );
		} );
	}

	function tick() {
		preselect();
		classicVisibility();
		syncDonate();
		renameHeading();
	}

	let scheduled = null;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = window.requestAnimationFrame( function () {
			scheduled = null;
			tick();
		} );
	}

	function start() {
		tick();
		setTimeout( tick, 300 );
		setTimeout( tick, 1000 );

		document.addEventListener( 'change', function ( e ) {
			const t = e.target;
			if ( ! t || ! t.matches ) {
				return;
			}
			if (
				t.matches( '[name*="invoice_type"]' ) ||
				t.matches( '[name*="invoice_carrier_type"]' ) ||
				t.matches( '[id*="invoice-type"]' ) ||
				t.matches( '[name*="moksafowo/invoice-type"]' ) ||
				t.matches( '[name*="invoice_donate_org"]' ) ||
				t.matches( '[id*="invoice-donate-org"]' ) ||
				t.matches( '[name*="moksafowo/invoice-donate-org"]' )
			) {
				schedule();
			}
		} );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', schedule );
		}

		const root = document.querySelector( '.wp-block-woocommerce-checkout, form.checkout, .wc-block-components-checkout-form' );
		if ( root ) {
			new MutationObserver( schedule ).observe( root, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
