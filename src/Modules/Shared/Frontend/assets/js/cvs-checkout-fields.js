/**
 * 超商選店往返時保住結帳already填好的欄位。
 *
 * 選店是整頁導離（表單 POST 到物流商的地圖）再導回結帳頁，回來是全新載入，
 * Classic 結帳表單的姓名／電話／地址全部會空掉 —— 顧客得重打一次，結帳流失就發生在這裡。
 *
 * 做法：離開前把整份表單寫進 sessionStorage（跟著分頁走、關掉自動清），回來再填回去。
 * 存檔由共用的 submitForm() 觸發，所以四家物流商自動都有，不必各自接。
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

	// ---- 區塊結帳 ----------------------------------------------------------------
	// 區塊結帳沒有 <form>，欄位存在 WooCommerce 的 wc/store/cart；它是打字後延遲一下才送到伺服器，
	// 顧客填完馬上按「選擇門市」就整頁導走，伺服器還沒收到 → 回來全空（實測：綠界／速買配／PAYUNi 都會）。
	// 離開前主動送一次（等它完成，最多 3 秒），另外在分頁暫存一份，回來時補回還是空的欄位。
	var BLOCK_KEY = 'moksafowo_cvs_block_customer';

	function cartStore() {
		try {
			return window.wp && window.wp.data ? window.wp.data.select( 'wc/store/cart' ) : null;
		} catch ( e ) {
			return null;
		}
	}

	function blockCustomer() {
		var store = cartStore();
		if ( ! store || ! store.getCustomerData ) {
			return null;
		}
		var d = store.getCustomerData() || {};
		var billing = d.billingAddress || d.billingData || null;
		if ( ! billing ) {
			return null;
		}
		return { billing: billing, shipping: d.shippingAddress || {} };
	}

	function saveBlock() {
		if ( form() ) {
			return null; // 傳統結帳頁
		}
		var data = blockCustomer();
		if ( ! data ) {
			return null;
		}
		try {
			window.sessionStorage.setItem( BLOCK_KEY, JSON.stringify( data ) );
		} catch ( e ) { /* 無痕模式：只靠送伺服器 */ }
		if ( ! window.wp || ! window.wp.apiFetch ) {
			return null;
		}
		return window.wp.apiFetch( {
			path: '/wc/store/v1/cart/update-customer',
			method: 'POST',
			data: { billing_address: data.billing, shipping_address: data.shipping },
		} ).catch( function () {} );
	}

	// 回到結帳頁：伺服器那份若沒存到（送出失敗、太快），用分頁暫存補回「目前還是空的」欄位，不蓋掉顧客新填的。
	function restoreBlock( tries ) {
		var raw = null;
		try {
			raw = window.sessionStorage.getItem( BLOCK_KEY );
		} catch ( e ) {
			return;
		}
		if ( ! raw || form() ) {
			return;
		}
		var now = blockCustomer();
		if ( ! now ) {
			if ( ( tries || 0 ) < 20 ) {
				window.setTimeout( function () {
					restoreBlock( ( tries || 0 ) + 1 );
				}, 300 );
			}
			return;
		}
		var saved;
		try {
			saved = JSON.parse( raw );
		} catch ( e ) {
			saved = null;
		}
		clearBlock();
		if ( ! saved ) {
			return;
		}
		var dispatch = window.wp.data.dispatch( 'wc/store/cart' );
		[ [ 'billing', 'setBillingAddress' ], [ 'shipping', 'setShippingAddress' ] ].forEach( function ( pair ) {
			var from = saved[ pair[ 0 ] ] || {};
			var cur = now[ pair[ 0 ] ] || {};
			var patch = {};
			var n = 0;
			Object.keys( from ).forEach( function ( k ) {
				if ( 'string' === typeof from[ k ] && '' !== from[ k ] && ! cur[ k ] ) {
					patch[ k ] = from[ k ];
					n++;
				}
			} );
			if ( n && dispatch && 'function' === typeof dispatch[ pair[ 1 ] ] ) {
				dispatch[ pair[ 1 ] ]( patch );
			}
		} );
	}

	function clearBlock() {
		try {
			window.sessionStorage.removeItem( BLOCK_KEY );
		} catch ( e ) { /* noop */ }
	}

	// 離開結帳頁去選店：先存（傳統表單同步存；區塊等送到伺服器，最多 3 秒），再執行 go()。
	function leave( go ) {
		var done = false;
		var finish = function () {
			if ( ! done ) {
				done = true;
				go();
			}
		};
		var pending = null;
		try {
			pending = save();
		} catch ( e ) {
			pending = null;
		}
		if ( pending && 'function' === typeof pending.then ) {
			pending.then( finish, finish );
			window.setTimeout( finish, 3000 );
		} else {
			finish();
		}
	}

	function save() {
		saveClassic();
		return saveBlock();
	}

	function saveClassic() {
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
		// 把他剛改的值蓋回舊的。下次要去選店時 submitForm() 會重新存。
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
			clearBlock();
			return;
		}
		restore();
		restoreBlock( 0 );

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
		leave: leave,
		restore: restore,
		clear: clear,
	};
}( window, document ) );
