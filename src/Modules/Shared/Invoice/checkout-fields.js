( function () {
	'use strict';

	function findField( namePart ) {
		const dashed = namePart.replace( /_/g, '-' );
		const selectors = [
			'[name="mo_' + namePart + '"]',                // classic
			'[id$="-mowp-' + dashed + '"]',                // block (location group prefix)
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

	function rowFor( namePart ) {
		const el = findField( namePart );
		if ( ! el ) {
			return null;
		}
		return el.closest( 'p, li, div.wc-block-components-text-input, div.wc-block-components-select-input, .form-row, tr' ) || el.parentElement;
	}

	function setRowVisible( row, visible ) {
		if ( row ) {
			row.style.display = visible ? '' : 'none';
		}
	}

	/**
	 * Classic 結帳的 label 上有「<span class="optional">(選填)</span>」或
	 * 「<abbr class="required" title="required">*</abbr>」。動態切換兩者，
	 * 表達「目前這個欄位是不是必填」。
	 */
	/**
	 * Block 的 label 把 "(選填)" 直接塞在 text node 裡（非 .optional span）—
	 * Classic 用 <span class="optional"> 包。所以兩邊都要處理：
	 *   - .optional / .mo-invoice-optional span 移除（Classic）
	 *   - text node 內的 "(選填)" / "(optional)" 字串移除（Block）
	 *
	 * 必填時加紅星 <abbr>；恢復時還原 "(選填)" 後綴避免 Block label 變空白。
	 */
	function setRequiredLabel( namePart, required ) {
		const el = findField( namePart );
		if ( ! el ) {
			return;
		}
		const row = rowFor( namePart );
		if ( ! row ) {
			return;
		}
		const label = row.querySelector( 'label' );
		if ( ! label ) {
			return;
		}

		// 1) 拿掉舊的 optional / required marker（不論 Classic 還是 JS-injected）
		label.querySelectorAll( '.optional, .mo-invoice-optional, .required, .mo-invoice-required' ).forEach( function ( n ) { n.remove(); } );

		// 2) text node 中的 "(選填)" / "(optional)" 也要清乾淨（Block 直接塞 text）
		label.childNodes.forEach( function ( node ) {
			if ( node.nodeType === Node.TEXT_NODE ) {
				node.nodeValue = node.nodeValue.replace( /\s*\(選填\)\s*/g, '' ).replace( /\s*\(optional\)\s*/gi, '' );
			}
		} );

		if ( required ) {
			const ab = document.createElement( 'abbr' );
			ab.className = 'required mo-invoice-required';
			ab.title = 'required';
			ab.textContent = '*';
			ab.style.cssText = 'color:#c62828;text-decoration:none;margin-left:4px;font-weight:bold;';
			label.appendChild( ab );
			el.required = true;
		} else {
			// 重新加上 "(選填)" 後綴（Classic 用 span，Block 直接 text）
			const sp = document.createElement( 'span' );
			sp.className = 'optional mo-invoice-optional';
			sp.textContent = ' (選填)';
			sp.style.cssText = 'color:#888;font-weight:normal;margin-left:4px;';
			label.appendChild( sp );
			el.required = false;
		}
	}

	/**
	 * 載具編號 row 的 label 跟 carrier_type 連動 — 因為手機條碼跟自然人憑證
	 * 格式天差地遠，但欄位 label 都叫「載具編號」會讓顧客困惑。直接把格式
	 * 提示寫進 label 本身（不另開 hint span / 不動 placeholder 避免 Block
	 * React hydration），單一視覺元素最乾淨。
	 *
	 *   mobile → 「手機條碼（/ 開頭 + 7 碼英數）」
	 *   cert   → 「自然人憑證（2 大寫字母 + 14 碼數字）」
	 *   member / paper → row 整個 hidden（不需處理 label）
	 */
	function setCarrierNumLabel( carrier ) {
		const row = rowFor( 'invoice_carrier_num' );
		if ( ! row ) return;
		const label = row.querySelector( 'label' );
		if ( ! label ) return;

		const newText = {
			mobile: '手機條碼（/ 開頭 + 7 碼英數）',
			cert:   '自然人憑證（2 大寫字母 + 14 碼數字）',
		}[ carrier ];
		if ( ! newText ) return;

		// 找 label 第一個 text node 改值（保留 .optional / .required 兄弟元素）
		// Classic 是 text node 直接 + .optional span；Block 也是 text node 為主
		let touched = false;
		label.childNodes.forEach( function ( n ) {
			if ( n.nodeType === Node.TEXT_NODE && ! touched && n.nodeValue.trim() !== '' ) {
				n.nodeValue = newText;
				touched = true;
			}
		} );
		if ( ! touched ) {
			label.insertBefore( document.createTextNode( newText ), label.firstChild );
		}
	}

	function applyState() {
		const typeSel    = findField( 'invoice_type' );
		const carrierSel = findField( 'invoice_carrier_type' );
		if ( ! typeSel ) {
			return;
		}
		// Block 不吃 register_additional_checkout_field 的 default — value=""
		// 直接送出去會被 required 擋下「請選取有效的選項」。預先把 select 設成
		// b2c_carrier（合理預設：個人載具）+ carrier_type 設 member。
		if ( '' === typeSel.value ) {
			Object.getOwnPropertyDescriptor( HTMLSelectElement.prototype, 'value' ).set.call( typeSel, 'b2c_carrier' );
			typeSel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		if ( carrierSel && '' === carrierSel.value ) {
			Object.getOwnPropertyDescriptor( HTMLSelectElement.prototype, 'value' ).set.call( carrierSel, 'member' );
			carrierSel.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		// 預設捐贈碼 — admin 在「進階設定 → 預設捐贈碼」填了就帶入 love_code 欄位
		const loveInput   = findField( 'invoice_love_code' );
		const defaultCode = ( window.mo_ecpay_invoice_defaults && window.mo_ecpay_invoice_defaults.love_code ) || '';
		if ( loveInput && defaultCode && '' === loveInput.value ) {
			Object.getOwnPropertyDescriptor( HTMLInputElement.prototype, 'value' ).set.call( loveInput, defaultCode );
			loveInput.dispatchEvent( new Event( 'input',  { bubbles: true } ) );
			loveInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
		const type    = typeSel.value || 'b2c_carrier';
		const carrier = carrierSel ? carrierSel.value : 'member';

		const rows = {
			carrier_type: rowFor( 'invoice_carrier_type' ),
			carrier_num:  rowFor( 'invoice_carrier_num' ),
			buyer_ubn:    rowFor( 'invoice_buyer_ubn' ),
			buyer_name:   rowFor( 'invoice_buyer_name' ),
			love_code:    rowFor( 'invoice_love_code' ),
		};

		// 1) 顯示／隱藏 row
		if ( 'b2c_carrier' === type ) {
			setRowVisible( rows.carrier_type, true );
			const needCarrierNum = ( 'mobile' === carrier || 'cert' === carrier );
			setRowVisible( rows.carrier_num, needCarrierNum );
			setRowVisible( rows.buyer_ubn, false );
			setRowVisible( rows.buyer_name, false );
			setRowVisible( rows.love_code, false );

			// 必填標記：carrier_type 必填；carrier_num 在 mobile/cert 時必填
			setRequiredLabel( 'invoice_carrier_type', true );
			if ( needCarrierNum ) {
				setRequiredLabel( 'invoice_carrier_num', true );
				// 依 carrier_type 改 label（手機條碼 / 自然人憑證 各自含格式提示）
				setCarrierNumLabel( carrier );
			}
		} else if ( 'b2b' === type ) {
			setRowVisible( rows.carrier_type, false );
			setRowVisible( rows.carrier_num, false );
			setRowVisible( rows.buyer_ubn, true );
			setRowVisible( rows.buyer_name, true );
			setRowVisible( rows.love_code, false );
			setRequiredLabel( 'invoice_buyer_ubn', true );
			setRequiredLabel( 'invoice_buyer_name', true );
		} else if ( 'b2c_donate' === type ) {
			setRowVisible( rows.carrier_type, false );
			setRowVisible( rows.carrier_num, false );
			setRowVisible( rows.buyer_ubn, false );
			setRowVisible( rows.buyer_name, false );
			setRowVisible( rows.love_code, true );
			setRequiredLabel( 'invoice_love_code', true );
		}

		// 發票類型本身永遠必填（用 user 不能空）
		setRequiredLabel( 'invoice_type', true );
	}

	let scheduled = null;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = window.requestAnimationFrame( function () {
			scheduled = null;
			applyState();
		} );
	}

	/**
	 * Block 「其他訂單資訊」(WC core 對 location='order' fields 的預設標題) 改成
	 * 「電子發票」— 以後其他發票廠商（ezPay / PayNow）啟用時會共用同個
	 * additional fields section，標題 generic 比較好。
	 */
	function renameOrderInfoHeading() {
		const headings = document.querySelectorAll( '.wp-block-woocommerce-checkout-additional-information-block h2, .wp-block-woocommerce-checkout-additional-information-block .wc-block-components-checkout-step__title' );
		headings.forEach( function ( h ) {
			const txt = ( h.innerText || '' ).trim();
			if ( txt === '其他訂單資訊' || txt === 'Additional order information' ) {
				h.innerText = '電子發票';
			}
		} );
	}

	function start() {
		applyState();
		renameOrderInfoHeading();
		setTimeout( applyState, 300 );
		setTimeout( renameOrderInfoHeading, 300 );
		setTimeout( applyState, 1000 );
		setTimeout( renameOrderInfoHeading, 1000 );

		document.addEventListener( 'change', function ( e ) {
			const t = e.target;
			if ( ! t || ! t.matches ) {
				return;
			}
			if (
				t.matches( '[name*="invoice_type"]' ) ||
				t.matches( '[name*="invoice_carrier_type"]' ) ||
				t.matches( '[id*="invoice-type"]' ) ||
				t.matches( '[id*="invoice-carrier-type"]' ) ||
				t.matches( '[name*="mowp/invoice-type"]' ) ||
				t.matches( '[name*="mowp/invoice-carrier-type"]' )
			) {
				applyState();
			}
		} );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', schedule );
		}

		const root = document.querySelector( '.wp-block-woocommerce-checkout, form.checkout, .wc-block-components-checkout-form' );
		if ( root ) {
			new MutationObserver( function () {
				schedule();
				renameOrderInfoHeading();
			} ).observe( root, { childList: true, subtree: true } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
