/**
 * Shared 「複製貨號」clipboard JS — 三家物流的顧客頁 + admin 訂單編輯頁共用。
 *
 * 之前 inline in TrackingLink::copy_script() 並透過 wp_footer / wp_add_inline_script
 * 各模組各印一份。集中成一支 .js 用 wp_register_script + wp_enqueue_script 取代。
 */
( function () {
	'use strict';
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.mo-tracking-copy' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		var tn = btn.getAttribute( 'data-tracking' ) || '';
		if ( ! tn ) {
			return;
		}
		var fallback = function () {
			var ta = document.createElement( 'textarea' );
			ta.value = tn;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';
			document.body.appendChild( ta );
			ta.select();
			try {
				document.execCommand( 'copy' );
			} catch ( _ ) {}
			document.body.removeChild( ta );
		};
		var flash = function () {
			var orig = btn.textContent;
			btn.textContent = '已複製';
			btn.style.background = '#dcfce7';
			btn.style.borderColor = '#86efac';
			btn.style.color = '#16a34a';
			setTimeout( function () {
				btn.textContent = orig;
				btn.style.background = '';
				btn.style.borderColor = '';
				btn.style.color = '';
			}, 1200 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( tn ).then( flash, fallback );
		} else {
			fallback();
			flash();
		}
	} );
} )();
