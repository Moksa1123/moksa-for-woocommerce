/**
 * ECPay / PAYUNi 設定頁 sandbox 切換 UX。
 *
 * 設計：每個模組都有兩組 credential 欄位（測試 / 正式），由 sandbox checkbox
 * 決定 Helper 走哪一邊。UI 上同時顯示兩組會混淆，所以 JS 只顯示「目前生效」
 * 的那組：
 *   - 勾選 sandbox：顯示「測試環境」row，隱藏「正式環境」row
 *   - 取消勾選：相反
 *
 * 切換不需儲存，real-time 反應。
 */
( function () {
	'use strict';

	// 每個 module 一筆：sandbox toggle id + 對應 sandbox / production 欄位 id
	const MODULES = [
		{
			toggle:  'moksafowo_ecpay_sandbox_enabled',
			sandbox: [ 'moksafowo_ecpay_sandbox_merchant_id', 'moksafowo_ecpay_sandbox_hash_key', 'moksafowo_ecpay_sandbox_hash_iv' ],
			prod:    [ 'moksafowo_ecpay_merchant_id', 'moksafowo_ecpay_hash_key', 'moksafowo_ecpay_hash_iv' ],
			label:   '綠界金流',
		},
		{
			toggle:  'moksafowo_ecpay_shipping_sandbox_enabled',
			sandbox: [ 'moksafowo_ecpay_shipping_sandbox_merchant_id', 'moksafowo_ecpay_shipping_sandbox_hash_key', 'moksafowo_ecpay_shipping_sandbox_hash_iv' ],
			prod:    [ 'moksafowo_ecpay_shipping_merchant_id', 'moksafowo_ecpay_shipping_hash_key', 'moksafowo_ecpay_shipping_hash_iv' ],
			label:   '綠界物流',
		},
		{
			toggle:  'moksafowo_ecpay_invoice_sandbox_enabled',
			sandbox: [ 'moksafowo_ecpay_invoice_sandbox_merchant_id', 'moksafowo_ecpay_invoice_sandbox_hash_key', 'moksafowo_ecpay_invoice_sandbox_hash_iv' ],
			prod:    [ 'moksafowo_ecpay_invoice_merchant_id', 'moksafowo_ecpay_invoice_hash_key', 'moksafowo_ecpay_invoice_hash_iv' ],
			label:   '綠界電子發票',
		},
		// PAYUNi 物流：同模式
		{
			toggle:  'moksafowo_payuni_shipping_testmode_enabled',
			sandbox: [ 'moksafowo_payuni_payment_merchant_id_test', 'moksafowo_payuni_payment_hashkey_test', 'moksafowo_payuni_payment_hashiv_test' ],
			prod:    [ 'moksafowo_payuni_payment_merchant_id', 'moksafowo_payuni_payment_hashkey', 'moksafowo_payuni_payment_hashiv' ],
			label:   'PAYUNi 物流',
		},
	];

	function rowOf( id ) {
		const input = document.getElementById( id );
		if ( ! input ) {
			return null;
		}
		return input.closest( 'tr' );
	}

	function ensureBanner( toggleId, label, isSandbox ) {
		const id = 'moksafowo-sandbox-banner-' + toggleId;
		let banner = document.getElementById( id );
		const text = isSandbox
			? '<strong>' + label + '：目前使用「測試環境」資料</strong>　' +
			  '<span style="opacity:.85">下方「正式環境」欄位不會被讀取，可保留資料供未來上線使用。</span>'
			: '<strong>' + label + '：目前使用「正式環境」資料</strong>　' +
			  '<span style="opacity:.85">下方「測試環境」欄位不會被讀取。</span>';
		// 測試 = 紅色（警示，提醒這是測試模式）；正式 = 藍色（中性）
		const bg = isSandbox ? '#ffebee' : '#e3f2fd';
		const fg = isSandbox ? '#b71c1c' : '#0d47a1';
		const accent = isSandbox ? '#c62828' : '#1565c0';

		if ( banner ) {
			banner.querySelector( 'td' ).innerHTML = text;
			banner.querySelector( 'td' ).style.background = bg;
			banner.querySelector( 'td' ).style.borderLeftColor = accent;
			banner.querySelector( 'td' ).style.color = fg;
			return banner;
		}
		const toggleRow = rowOf( toggleId );
		if ( ! toggleRow ) {
			return null;
		}
		banner = document.createElement( 'tr' );
		banner.id = id;
		banner.className = 'moksafowo-sandbox-banner';
		const td = document.createElement( 'td' );
		td.colSpan = 2;
		td.style.cssText = 'padding:8px 12px;background:' + bg + ';border-left:4px solid ' + accent + ';color:' + fg + ';font-size:13px;';
		td.innerHTML = text;
		banner.appendChild( td );
		toggleRow.parentNode.insertBefore( banner, toggleRow.nextSibling );
		return banner;
	}

	function setRowVisible( row, visible ) {
		if ( ! row ) {
			return;
		}
		// 只 hide row，不要 disable input — disabled 欄位 browser 不會送出 form，
		// WC settings save 會收到空字串，把另一邊原本儲存的 credentials 蓋掉。
		row.style.display = visible ? '' : 'none';
	}

	function applyState( mod ) {
		const toggle = document.getElementById( mod.toggle );
		if ( ! toggle ) {
			return;
		}
		const sandboxOn = toggle.checked;
		ensureBanner( mod.toggle, mod.label, sandboxOn );
		mod.sandbox.forEach( function ( fieldId ) {
			setRowVisible( rowOf( fieldId ), sandboxOn );
		} );
		mod.prod.forEach( function ( fieldId ) {
			setRowVisible( rowOf( fieldId ), ! sandboxOn );
		} );
	}

	function init() {
		MODULES.forEach( function ( mod ) {
			const toggle = document.getElementById( mod.toggle );
			if ( ! toggle ) {
				return;
			}
			applyState( mod );
			toggle.addEventListener( 'change', function () { applyState( mod ); } );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
