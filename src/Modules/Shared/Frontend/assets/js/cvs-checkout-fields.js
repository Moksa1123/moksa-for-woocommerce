/**
 * 超商選店往返時保住結帳already填好的欄位。
 *
 * 選店是整頁導離（表單 POST 到物流商的地圖）再導回結帳頁，回來是全新載入，
 * Classic 結帳表單的姓名／電話／地址全部會空掉 —— 顧客得重打一次，結帳流失就發生在這裡。
 *
 * 做法：離開前把整份表單寫進 sessionStorage（跟著分頁走、關掉自動清），回來再填回去。
 * 存檔由共用的 submitForm() 觸發，所以四家物流商自動都有，不必各自接。
 *
 * 原本只有 PAYUNi 有這個機制（PayuniShipping/assets/js/save-fields.js），
 * 綠界／藍新／速買配都沒有；這裡是把它一般化後收成一份。
 */
( function ( window, document ) {
	'use strict';

	var STORAGE_KEY = 'moksafowo_cvs_checkout_form';
	var restored = false;
	var attempts = 0;

	// 還原這些會出事，一律不存：
	// - WC 的各種 nonce：還原成舊值會讓 wp_verify_nonce() 失敗，顧客送單時看到
	//   「我們無法處理您的訂單」（WC core class-wc-checkout.php）
	// - 門市欄位：選店流程自己會寫，還原舊值會蓋掉剛選好的門市
	// - 條款同意 / recaptcha：法遵與安全上都該讓顧客自己再確認一次
	var IGNORE = [
		'terms',
		'terms-field',
		'g-recaptcha-response',
		'mailchimp_woocommerce_newsletter',
	];

	function ignored( name ) {
		if ( ! name ) {
			return true;
		}
		if ( IGNORE.indexOf( name ) !== -1 ) {
			return true;
		}
		if ( name.charAt( 0 ) === '_' ) {          // _wpnonce / _wp_http_referer
			return true;
		}
		if ( name.indexOf( 'nonce' ) !== -1 ) {    // woocommerce-process-checkout-nonce 等
			return true;
		}
		if ( name.indexOf( 'moksafowo_' ) === 0 ) { // 門市欄位由選店流程負責
			return true;
		}
		return false;
	}

	function form() {
		return document.querySelector( 'form.woocommerce-checkout' );
	}

	// 就算值是空的也要留，否則「顧客刻意清空」會被舊值蓋回去
	var KEEP_EMPTY = [
		'billing_first_name', 'billing_last_name', 'billing_email', 'billing_phone',
		'shipping_first_name', 'shipping_last_name',
	];

	function save() {
		var f = form();
		if ( ! f ) {
			return;
		}
		var out = [];
		Array.prototype.forEach.call( f.elements, function ( el ) {
			var name = el.name;
			if ( ! name || ignored( name ) || el.disabled ) {
				return;
			}
			if ( el.type === 'checkbox' || el.type === 'radio' ) {
				if ( el.checked ) {
					out.push( { name: name, value: el.value, type: el.type } );
				}
				return;
			}
			if ( el.tagName === 'SELECT' && el.multiple ) {
				Array.prototype.forEach.call( el.selectedOptions, function ( o ) {
					out.push( { name: name, value: o.value, type: 'select-multiple' } );
				} );
				return;
			}
			if ( el.value !== '' || KEEP_EMPTY.indexOf( name ) !== -1 ) {
				out.push( { name: name, value: el.value, type: 'value' } );
			}
		} );
		if ( out.length ) {
			try {
				window.sessionStorage.setItem( STORAGE_KEY, JSON.stringify( out ) );
			} catch ( e ) { /* 無痕模式可能整個不給用，放棄還原但不能擋住選店 */ }
		}
	}

	function stored() {
		try {
			var raw = window.sessionStorage.getItem( STORAGE_KEY );
			return raw ? JSON.parse( raw ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function fire( el, name ) {
		el.dispatchEvent( new Event( name, { bubbles: true } ) );
	}

	function restore() {
		if ( restored ) {
			return;
		}
		var values = stored();
		if ( ! values || ! values.length ) {
			return;
		}
		var f = form();
		if ( ! f ) {
			return;
		}

		restored = true;
		var missing = 0;

		values.forEach( function ( item ) {
			var nodes = f.querySelectorAll( '[name="' + item.name.replace( /"/g, '\\"' ) + '"]' );
			if ( ! nodes.length ) {
				missing++;
				return;
			}
			if ( item.type === 'checkbox' || item.type === 'radio' ) {
				Array.prototype.forEach.call( nodes, function ( el ) {
					if ( el.value === item.value && ! el.checked ) {
						el.checked = true;
						fire( el, 'change' );
					}
				} );
				return;
			}
			var el = nodes[ 0 ];
			if ( item.type === 'select-multiple' ) {
				Array.prototype.forEach.call( el.options, function ( o ) {
					if ( o.value === item.value ) {
						o.selected = true;
					}
				} );
				fire( el, 'change' );
				return;
			}
			// 值一樣就不要動，避免無謂觸發 WC 的 update_checkout（會多打好幾次 AJAX）
			if ( el.value !== item.value ) {
				el.value = item.value;
				fire( el, 'change' );
			}
		} );

		// 欄位大半找不到 = 表單還沒渲染完（例如國家換了在重畫地址欄），等下一輪再試
		if ( missing > values.length * 0.5 && attempts < 3 ) {
			restored = false;
			attempts++;
			window.setTimeout( restore, 400 );
			return;
		}

		// 還原成功就把暫存清掉，這份資料只能生效一次。
		// 不清的話，顧客回來後自己改了欄位，下一次 updated_checkout 會再還原一遍，
		// 把他剛改的值蓋回舊的 —— 實測踩過。下次要去選店時 submitForm() 會重新存。
		clear();
	}

	function clear() {
		try {
			window.sessionStorage.removeItem( STORAGE_KEY );
		} catch ( e ) { /* noop */ }
	}

	function init() {
		if ( document.body && document.body.classList.contains( 'woocommerce-order-received' ) ) {
			clear();
			return;
		}
		restore();

		// WC 重畫結帳區塊後欄位可能被換掉，重試幾次。updated_checkout 是 jQuery 自訂事件，
		// 只有 jQuery 監聽得到。
		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'updated_checkout', function () {
				if ( stored() && attempts < 3 ) {
					restored = false;
					attempts++;
					window.setTimeout( restore, 300 );
				}
			} );
			window.jQuery( document.body ).on( 'checkout_place_order_success', clear );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}

	window.moksafowoCvsFields = {
		save: save,
		restore: restore,
		clear: clear,
	};
}( window, document ) );
