/**
 * 共用發票 admin metabox JS — Issue / Update / Invalid / Allowance。
 *
 * 各 provider 透過 AbstractAdminMetaBox 渲染 div.moksafowo-invoice-meta with
 * data-provider + data-prefix="moksafowo_amego_invoice" + nonce field；本 JS 從 data attr
 * 讀 prefix 動態組 AJAX action。
 */
( function ( $ ) {
	'use strict';
	if ( ! window.moksafowo_invoice_admin_i18n ) {
		return;
	}
	const cfg = window.moksafowo_invoice_admin_i18n;

	function ctx( $btn ) {
		const $box = $btn.closest( '.moksafowo-invoice-meta' );
		const prefix = $box.data( 'prefix' );
		return {
			$btn:   $btn,
			$box:   $box,
			order:  $box.data( 'order-id' ),
			prefix: prefix,
			nonce:  $box.find( 'input[name="' + prefix + '_nonce"]' ).val(),
		};
	}

	function fail( prefix, resp ) {
		return prefix + ( ( resp && resp.data && resp.data.message ) || cfg.unknown_error );
	}

	// 訊息顯示在 metabox 內（取代 alert）。
	function showMsg( c, text, isError ) {
		c.$box.find( '.moksafowo-inv-msg' ).text( text || '' ).css( 'color', isError ? '#c00' : '#1a7f37' );
	}

	function restore( c, busyText ) {
		c.$btn.prop( 'disabled', false );
		if ( busyText ) {
			c.$btn.text( c.$btn.data( 'orig' ) || '' );
		}
	}

	function run( c, payload, okMsg, failPrefix, busyText ) {
		c.$btn.prop( 'disabled', true );
		if ( busyText ) {
			c.$btn.data( 'orig', c.$btn.text() ).text( busyText );
		}
		showMsg( c, '', false );
		$.post( cfg.ajax_url, payload ).done( function ( resp ) {
			if ( resp && resp.success ) {
				showMsg( c, okMsg, false );
				window.location.reload();
			} else {
				showMsg( c, fail( failPrefix, resp ), true );
				restore( c, busyText );
			}
		} ).fail( function () {
			showMsg( c, fail( failPrefix, null ), true );
			restore( c, busyText );
		} );
	}

	function fieldPayload( $box ) {
		return {
			inv_type:        $box.find( '.moksafowo-inv-type' ).val() || '',
			inv_carrier:     $box.find( '.moksafowo-inv-carrier-type' ).val() || '',
			inv_carrier_num: $box.find( '.moksafowo-inv-carrier-num' ).val() || '',
			inv_ubn:         $box.find( '.moksafowo-inv-buyer-ubn' ).val() || '',
			inv_name:        $box.find( '.moksafowo-inv-buyer-name' ).val() || '',
			inv_donate:      $box.find( '.moksafowo-inv-love-code' ).val() || '',
		};
	}

	// 依發票類型檢查必填欄位；缺漏回傳錯誤字串（阻擋更新 / 開立），齊全回 null。
	function validateFields( $box ) {
		const type = $box.find( '.moksafowo-inv-type' ).val();
		if ( type === 'b2c_donate' ) {
			if ( ! ( $box.find( '.moksafowo-inv-love-code' ).val() || '' ).trim() ) { return cfg.need_donate; }
		} else if ( type === 'b2b' ) {
			if ( ! ( $box.find( '.moksafowo-inv-buyer-ubn' ).val() || '' ).trim() ) { return cfg.need_ubn; }
		} else if ( type === 'b2c_carrier' ) {
			const carrier = $box.find( '.moksafowo-inv-carrier-type' ).val();
			if ( ( carrier === 'mobile' || carrier === 'cert' ) && ! ( $box.find( '.moksafowo-inv-carrier-num' ).val() || '' ).trim() ) { return cfg.need_cnum; }
		}
		return null;
	}

	// 後台手動開立表單 — 依發票類型 / 載具顯示對應欄位。
	function syncInvoiceFields( $box ) {
		const type = $box.find( '.moksafowo-inv-type' ).val();
		const carrier = $box.find( '.moksafowo-inv-carrier-type' ).val();
		const needCnum = type === 'b2c_carrier' && ( carrier === 'mobile' || carrier === 'cert' );
		$box.find( '.moksafowo-inv-carrier' ).toggle( type === 'b2c_carrier' );
		$box.find( '.moksafowo-inv-cnum' ).toggle( needCnum );
		if ( needCnum ) {
			$box.find( '.moksafowo-inv-cnum-label' ).text( carrier === 'mobile' ? cfg.cnum_mobile : cfg.cnum_cert );
		}
		$box.find( '.moksafowo-inv-ubn, .moksafowo-inv-name' ).toggle( type === 'b2b' );
		$box.find( '.moksafowo-inv-donate' ).toggle( type === 'b2c_donate' );
	}

	function formSnapshot( $box ) {
		const type = $box.find( '.moksafowo-inv-type' ).val();
		const carrier = $box.find( '.moksafowo-inv-carrier-type' ).val() || '';
		const cnum = $box.find( '.moksafowo-inv-carrier-num' ).val() || '';
		const ubn = $box.find( '.moksafowo-inv-buyer-ubn' ).val() || '';
		const name = $box.find( '.moksafowo-inv-buyer-name' ).val() || '';
		const donate = $box.find( '.moksafowo-inv-love-code' ).val() || '';
		if ( type === 'b2b' ) {
			return { type: type, carrier: '', cnum: '', ubn: ubn, name: name, donate: '' };
		}
		if ( type === 'b2c_donate' ) {
			return { type: type, carrier: '', cnum: '', ubn: '', name: '', donate: donate };
		}
		const c = carrier || 'member';
		return { type: type, carrier: c, cnum: ( c === 'mobile' || c === 'cert' ) ? cnum : '', ubn: '', name: '', donate: '' };
	}

	function savedSnapshot( $form ) {
		return {
			type:    $form.attr( 'data-saved-type' ) || '',
			carrier: $form.attr( 'data-saved-carrier' ) || '',
			cnum:    $form.attr( 'data-saved-cnum' ) || '',
			ubn:     $form.attr( 'data-saved-ubn' ) || '',
			name:    $form.attr( 'data-saved-name' ) || '',
			donate:  $form.attr( 'data-saved-donate' ) || '',
		};
	}

	function isDirty( $box ) {
		const $form = $box.find( '.moksafowo-inv-issue-form' );
		if ( ! $form.length ) {
			return false;
		}
		const f = formSnapshot( $box );
		const s = savedSnapshot( $form );
		return f.type !== s.type || f.carrier !== s.carrier || f.cnum !== s.cnum || f.ubn !== s.ubn || f.name !== s.name || f.donate !== s.donate;
	}

	// 有未存的修改 → 「開立發票」不能按，要先「更新」儲存並重整確認。
	function refreshIssue( $box ) {
		if ( ! $box.find( '.moksafowo-inv-issue-form' ).length ) {
			return;
		}
		const dirty = isDirty( $box );
		$box.find( '.moksafowo-invoice-issue' ).prop( 'disabled', dirty );
		$box.find( '.moksafowo-inv-dirty-hint' ).toggle( dirty );
	}

	$( function () {
		$( '.moksafowo-invoice-meta' ).each( function () {
			syncInvoiceFields( $( this ) );
			refreshIssue( $( this ) );
		} );
	} );

	$( document ).on( 'change keyup', '.moksafowo-invoice-meta .moksafowo-inv-type, .moksafowo-invoice-meta .moksafowo-inv-carrier-type, .moksafowo-invoice-meta .moksafowo-inv-carrier-num, .moksafowo-invoice-meta .moksafowo-inv-buyer-ubn, .moksafowo-invoice-meta .moksafowo-inv-buyer-name, .moksafowo-invoice-meta .moksafowo-inv-love-code', function () {
		const $box = $( this ).closest( '.moksafowo-invoice-meta' );
		syncInvoiceFields( $box );
		refreshIssue( $box );
	} );

	// 捐贈單位下拉 → 帶入捐贈碼（唯讀欄位）。
	$( document ).on( 'change', '.moksafowo-invoice-meta .moksafowo-inv-donate-org', function () {
		const $box = $( this ).closest( '.moksafowo-invoice-meta' );
		$box.find( '.moksafowo-inv-love-code' ).val( $( this ).val() || '' ).trigger( 'change' );
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-update', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		const err = validateFields( c.$box );
		if ( err ) { showMsg( c, err, true ); return; }
		run( c, $.extend( { action: c.prefix + '_save', order_id: c.order, nonce: c.nonce }, fieldPayload( c.$box ) ),
			cfg.updated, cfg.update_fail, cfg.updating );
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-issue', function ( e ) {
		e.preventDefault();
		if ( $( this ).prop( 'disabled' ) ) {
			return;
		}
		const c = ctx( $( this ) );
		const err = validateFields( c.$box );
		if ( err ) { showMsg( c, err, true ); return; }
		run( c, $.extend( { action: c.prefix + '_issue', order_id: c.order, nonce: c.nonce }, fieldPayload( c.$box ) ),
			cfg.issue_ok, cfg.issue_fail, cfg.issuing );
	} );

	// 作廢 — 內聯原因輸入（取代 prompt/alert）。
	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-invalid', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.moksafowo-invoice-meta' ).find( '.moksafowo-inv-invalid-form' ).show().find( '.moksafowo-inv-invalid-reason' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-inv-invalid-cancel', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.moksafowo-inv-invalid-form' ).hide();
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-invalid-confirm', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		const reason = ( c.$box.find( '.moksafowo-inv-invalid-reason' ).val() || '' ).trim();
		if ( ! reason ) {
			showMsg( c, cfg.invalid_need_reason, true );
			return;
		}
		run( c, { action: c.prefix + '_invalid', order_id: c.order, reason: reason, nonce: c.nonce },
			cfg.invalid_ok, cfg.invalid_fail, cfg.invalidating );
	} );

	// 折讓 — 內聯金額輸入（取代 prompt/alert）。
	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-allowance', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.moksafowo-invoice-meta' ).find( '.moksafowo-inv-allowance-form' ).show().find( '.moksafowo-inv-allowance-amount' ).trigger( 'focus' );
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-inv-allowance-cancel', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.moksafowo-inv-allowance-form' ).hide();
	} );

	$( document ).on( 'click', '.moksafowo-invoice-meta .moksafowo-invoice-allowance-confirm', function ( e ) {
		e.preventDefault();
		const c = ctx( $( this ) );
		const amt = ( c.$box.find( '.moksafowo-inv-allowance-amount' ).val() || '' ).trim();
		if ( ! amt || Number( amt ) <= 0 ) {
			showMsg( c, cfg.allowance_need_amount, true );
			return;
		}
		run( c, { action: c.prefix + '_allowance', order_id: c.order, amount: amt, nonce: c.nonce },
			cfg.allowance_ok, cfg.allowance_fail, cfg.allowancing );
	} );
} )( jQuery );
