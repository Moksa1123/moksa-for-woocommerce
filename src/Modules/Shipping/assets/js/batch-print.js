( function ( $ ) {
	'use strict';
	if ( ! window.moksafowo_shipping_batch_print ) {
		return;
	}
	const cfg = window.moksafowo_shipping_batch_print;

	$( function () {
		injectButtons();
	} );

	function injectButtons() {
		const $anchor = $( '.wrap .page-title-action' ).first();
		if ( ! $anchor.length ) {
			return;
		}
		// 把每顆按鈕當作 .page-title-action 的兄弟節點直接 append，
		// 套 WP 原生 .page-title-action style → 跟「新增訂單」對齊，wrap 自然。
		let $cursor = $anchor;
		cfg.providers.forEach( function ( p ) {
			const icon = p.category === 'cvs' ? 'dashicons-store' : 'dashicons-car';
			const $btn = $( '<button type="button" class="page-title-action moksafowo-batch-btn"></button>' )
				.attr( 'data-provider', p.key )
				.html( '<span class="dashicons ' + icon + '"></span> ' + escapeHtml( p.label ) );
			$cursor.after( $btn );
			$cursor = $btn;
		} );
		$( document ).on( 'click', '.moksafowo-batch-btn', onClickProvider );
	}

	function escapeHtml( s ) {
		return String( s == null ? '' : s )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function onClickProvider( e ) {
		e.preventDefault();
		const $btn     = $( this );
		const provider = $btn.attr( 'data-provider' );
		const label    = $btn.text().trim();
		openModal( provider, label );
	}

	let currentProvider = null;
	let currentRows     = [];

	function openModal( provider, label ) {
		currentProvider = provider;
		const $modal = $( '#moksafowo-shipping-batch-print-modal' );
		$modal.find( '.moksafowo-batch-modal__title' ).text( cfg.i18n.modal_title + '：' + label );
		$modal.find( '.moksafowo-batch-modal__cancel' ).text( cfg.i18n.cancel );
		$modal.find( '.moksafowo-batch-modal__print' ).text( cfg.i18n.print.replace( '%d', '0' ) ).prop( 'disabled', true );
		$modal.find( '.moksafowo-batch-modal__body' ).html( '<p>' + escapeHtml( cfg.i18n.loading ) + '</p>' );

		// 依 provider 宣告的 paper_modes 反灰 select 選項（A4=1, A6=2）
		const meta = ( cfg.providers || [] ).find( function ( p ) { return p.key === provider; } );
		const allowed = meta && meta.paper_modes ? meta.paper_modes : [ '1', '2' ];
		const $sel = $modal.find( '.moksafowo-batch-mode__select' );
		const unsupportedSuffix = '（此物流不支援）';
		$sel.find( 'option' ).each( function () {
			const $opt = $( this );
			const v = $opt.attr( 'value' );
			const disabled = allowed.indexOf( v ) === -1;
			$opt.prop( 'disabled', disabled );
			// 維護原始 label 在 data-base，加 / 移除 suffix
			if ( ! $opt.attr( 'data-base' ) ) {
				$opt.attr( 'data-base', $opt.text() );
			}
			$opt.text( disabled ? $opt.attr( 'data-base' ) + unsupportedSuffix : $opt.attr( 'data-base' ) );
		} );
		if ( allowed.indexOf( $sel.val() ) === -1 ) {
			$sel.val( allowed[ 0 ] || '1' );
		}

		$modal.show();

		$.post( cfg.ajax_url, {
			action:   'moksafowo_shipping_batch_print_list',
			nonce:    cfg.nonce,
			provider: provider,
		} ).done( function ( resp ) {
			if ( ! resp || ! resp.success ) {
				$modal.find( '.moksafowo-batch-modal__body' ).html( '<p>' + escapeHtml( ( resp && resp.data && resp.data.message ) || cfg.i18n.error ) + '</p>' );
				return;
			}
			currentRows = resp.data.rows || [];
			renderRows( currentRows );
		} ).fail( function () {
			$modal.find( '.moksafowo-batch-modal__body' ).html( '<p>' + escapeHtml( cfg.i18n.error ) + '</p>' );
		} );
	}

	function renderRows( rows ) {
		const $body = $( '#moksafowo-shipping-batch-print-modal .moksafowo-batch-modal__body' );
		if ( ! rows.length ) {
			$body.html( '<p>' + escapeHtml( cfg.i18n.no_orders ) + '</p>' );
			return;
		}
		const currentMode = $( '.moksafowo-batch-mode__select' ).val() || '1';
		let html = '<table class="widefat striped moksafowo-batch-table"><thead><tr>';
		html += '<th class="check-col"><input type="checkbox" class="moksafowo-batch-all"></th>';
		html += '<th style="width:80px;">' + escapeHtml( cfg.i18n.order_no ) + '</th>';
		html += '<th>' + escapeHtml( cfg.i18n.recipient ) + '</th>';
		html += '<th>' + escapeHtml( cfg.i18n.method ) + '</th>';
		html += '<th style="width:80px;">' + escapeHtml( cfg.i18n.status ) + '</th>';
		html += '<th class="printable-col">' + escapeHtml( cfg.i18n.printable ) + '</th>';
		html += '</tr></thead><tbody>';
		// 溫層 pill 顏色（跟 OrderMetaBox UI 一致）
		const TEMP_LABELS = { 1: '常溫', 2: '冷藏', 3: '冷凍' };
		const TEMP_BG     = { 1: '#e5e7eb', 2: '#dbeafe', 3: '#ede9fe' };
		const TEMP_FG     = { 1: '#374151', 2: '#1e40af', 3: '#6d28d9' };
		const tempPill = function ( t ) {
			const label = TEMP_LABELS[ t ] || '';
			if ( ! label ) return '';
			return '<span style="background:' + TEMP_BG[ t ] + ';color:' + TEMP_FG[ t ] + ';padding:1px 6px;border-radius:3px;font-size:10px;margin-left:4px;white-space:nowrap;">' + escapeHtml( label ) + '</span>';
		};

		rows.forEach( function ( r ) {
			// row 紙張支援：若 row.paper_modes 不含 currentMode → 此 row 視為不支援當前紙張
			const rowModes      = r.paper_modes || [ '1', '2' ];
			const supportsPaper = rowModes.indexOf( currentMode ) !== -1;
			const printable     = r.printable && supportsPaper;
			const disabled      = ! printable ? 'disabled' : '';
			const records       = r.records | 0;
			// 「可印」column：拆單訂單顯示「✓ 3 件」一眼看出是多溫層
			const mark = printable
				? ( records > 1 ? cfg.i18n.yes + ' ' + records + ' 件' : cfg.i18n.yes )
				: cfg.i18n.no;
			const markStyle = printable ? 'color:#00a32a;font-weight:600;' : 'color:#a7aaad;';
			// 顯示原因：若是「紙張不相容」就提示，否則就一般 dim
			let methodCell = escapeHtml( r.method );
			// 溫層 pills（拆單訂單會 > 1）— 單溫層也顯示，跟 OrderMetaBox UI 一致
			const temps = ( r.temps || [] ).filter( function ( t ) { return TEMP_LABELS[ t ]; } );
			if ( temps.length > 0 ) {
				methodCell += ' ' + temps.map( tempPill ).join( '' );
			}
			if ( r.printable && ! supportsPaper ) {
				methodCell += ' <span style="color:#d63638;font-size:11px;">（不支援 ' + ( currentMode === '2' ? 'A6' : 'A4' ) + '）</span>';
			}
			html += '<tr class="' + ( printable ? '' : 'moksafowo-batch-disabled' ) + '" data-paper-modes="' + escapeHtml( rowModes.join( ',' ) ) + '">';
			html += '<td class="check-col"><input type="checkbox" class="moksafowo-batch-row" value="' + r.id + '" ' + disabled + '></td>';
			html += '<td>#' + escapeHtml( r.id ) + '</td>';
			html += '<td>' + escapeHtml( r.name ) + '</td>';
			html += '<td>' + methodCell + '</td>';
			html += '<td>' + escapeHtml( r.status || '' ) + '</td>';
			html += '<td class="printable-col" style="' + markStyle + '">' + mark + '</td>';
			html += '</tr>';
		} );
		html += '</tbody></table>';
		$body.html( html );
	}

	// 紙張下拉切換 → 重新算 row printability + 重置「列印 (N)」按鈕
	$( document ).on( 'change', '.moksafowo-batch-mode__select', function () {
		renderRows( currentRows );
		const $btn = $( '.moksafowo-batch-modal__print' );
		$btn.text( cfg.i18n.print.replace( '%d', '0' ) ).prop( 'disabled', true );
	} );

	$( document ).on( 'change', '.moksafowo-batch-all', function () {
		const checked = $( this ).prop( 'checked' );
		$( '.moksafowo-batch-row:not(:disabled)' ).prop( 'checked', checked ).trigger( 'change' );
	} );

	$( document ).on( 'change', '.moksafowo-batch-row', function () {
		const n = $( '.moksafowo-batch-row:checked' ).length;
		const $btn = $( '.moksafowo-batch-modal__print' );
		$btn.text( cfg.i18n.print.replace( '%d', n ) ).prop( 'disabled', n === 0 );
	} );

	$( document ).on( 'click', '.moksafowo-batch-modal__close, .moksafowo-batch-modal__cancel', function () {
		$( '#moksafowo-shipping-batch-print-modal' ).hide();
		currentProvider = null;
	} );

	$( document ).on( 'click', '.moksafowo-batch-modal__print', function () {
		const ids = $( '.moksafowo-batch-row:checked' ).map( function () { return $( this ).val(); } ).get();
		if ( ! ids.length ) {
			alert( cfg.i18n.select_one );
			return;
		}
		const $btn = $( this ).prop( 'disabled', true );
		const mode = $( '.moksafowo-batch-mode__select' ).val() || '1';
		$.post( cfg.ajax_url, {
			action:    'moksafowo_shipping_batch_print_run',
			nonce:     cfg.nonce,
			provider:  currentProvider,
			order_ids: ids,
			mode:      mode,
		} ).done( function ( resp ) {
			if ( resp && resp.success && resp.data && Array.isArray( resp.data.forms ) ) {
				resp.data.forms.forEach( submitInWindow );
				$( '#moksafowo-shipping-batch-print-modal' ).hide();
			} else {
				alert( ( resp && resp.data && resp.data.message ) || cfg.i18n.error );
			}
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	function submitInWindow( spec ) {
		const w = window.open( '', '_blank' );
		if ( ! w ) {
			return;
		}
		let html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>printing…</title></head><body>';
		html += '<form id="f" method="post" action="' + escapeAttr( spec.api_url ) + '">';
		Object.keys( spec.form_data || {} ).forEach( function ( k ) {
			html += '<input type="hidden" name="' + escapeAttr( k ) + '" value="' + escapeAttr( spec.form_data[ k ] ) + '">';
		} );
		html += '</form><script>document.getElementById("f").submit();<\/script></body></html>';
		w.document.open();
		w.document.write( html );
		w.document.close();
	}

	function escapeAttr( s ) {
		return String( s == null ? '' : s ).replace( /"/g, '&quot;' ).replace( /</g, '&lt;' );
	}
} )( jQuery );
