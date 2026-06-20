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
			'[id$="-mowp-' + dashed + '"]',        // block（location group prefix）
			'[name$="-mowp-' + dashed + '"]',
			'[name*="mowp/' + dashed + '"]',
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
	 * Block 欄位用不同 name（mowp/...），不會被這裡選到，維持由 JSON Schema 控制。
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

		showRow( classicRow( 'invoice_carrier_type' ), isCarrier );
		showRow( classicRow( 'invoice_carrier_num' ), needNum );
		showRow( classicRow( 'invoice_buyer_ubn' ), 'b2b' === type );
		showRow( classicRow( 'invoice_buyer_name' ), 'b2b' === type );
		showRow( classicRow( 'invoice_donate_org' ), 'b2c_donate' === type );
		showRow( classicRow( 'invoice_love_code' ), 'b2c_donate' === type );
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
				t.matches( '[name*="mowp/invoice-type"]' ) ||
				t.matches( '[name*="invoice_donate_org"]' ) ||
				t.matches( '[id*="invoice-donate-org"]' ) ||
				t.matches( '[name*="mowp/invoice-donate-org"]' )
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
