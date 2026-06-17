/**
 * Moksa AI 浮動對話窗 — 後台右下角 AI 助手。訊息經 REST(mo-ectools/v1/ai-chat)走
 * agentic 迴圈(AI 用我們的 ability 查訂單後回答)。名稱/文案由 wp_localize 的 moksafowoAi 帶入。
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || ! document.body ) {
		return;
	}
	var apiFetch = wp.apiFetch;
	var cfg = window.moksafowoAi || {};
	var NAME = cfg.name || 'Moksa AI';
	var GREETING = cfg.greeting || '';
	var EXAMPLES = cfg.examples || [];

	var style = document.createElement( 'style' );
	style.textContent =
		'#mo-ai-fab{position:fixed;right:22px;bottom:22px;z-index:99998;display:flex;align-items:center;gap:8px;' +
		'background:linear-gradient(135deg,#2271b1,#135e96);color:#fff;border:0;border-radius:24px;padding:11px 18px;' +
		'cursor:pointer;box-shadow:0 4px 14px rgba(19,94,150,.35);font-size:14px;font-weight:600;transition:transform .15s,box-shadow .15s;}' +
		'#mo-ai-fab:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(19,94,150,.45);}' +
		'#mo-ai-panel{position:fixed;right:22px;bottom:80px;z-index:99999;width:360px;max-width:92vw;height:520px;max-height:78vh;' +
		'display:none;flex-direction:column;background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.22);overflow:hidden;' +
		'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans TC",sans-serif;animation:mo-ai-in .18s ease;}' +
		'@keyframes mo-ai-in{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:none;}}' +
		'.mo-ai-head{background:linear-gradient(135deg,#2271b1,#135e96);color:#fff;padding:13px 16px;display:flex;align-items:center;gap:9px;}' +
		'.mo-ai-head .mo-ai-dot{width:9px;height:9px;border-radius:50%;background:#46d39a;box-shadow:0 0 0 3px rgba(70,211,154,.3);}' +
		'.mo-ai-head b{font-size:15px;font-weight:700;}' +
		'.mo-ai-head .mo-ai-beta{font-size:11px;opacity:.8;font-weight:400;}' +
		'.mo-ai-head .mo-ai-clear{margin-left:auto;background:rgba(255,255,255,.18);border:0;color:#fff;border-radius:8px;padding:4px 9px;font-size:12px;cursor:pointer;}' +
		'.mo-ai-head .mo-ai-clear:hover{background:rgba(255,255,255,.3);}' +
		'.mo-ai-msgs{flex:1;overflow-y:auto;padding:14px;background:#f4f6f8;font-size:13.5px;line-height:1.6;}' +
		'.mo-ai-row{margin:10px 0;display:flex;gap:8px;align-items:flex-end;}' +
		'.mo-ai-row.user{justify-content:flex-end;}' +
		'.mo-ai-av{width:26px;height:26px;border-radius:50%;flex:0 0 26px;display:flex;align-items:center;justify-content:center;' +
		'background:linear-gradient(135deg,#2271b1,#135e96);color:#fff;font-size:14px;}' +
		'.mo-ai-bub{max-width:75%;padding:9px 12px;border-radius:14px;white-space:pre-wrap;word-break:break-word;}' +
		'.mo-ai-row.bot .mo-ai-bub{background:#fff;border:1px solid #e4e7eb;color:#1d2327;border-bottom-left-radius:4px;}' +
		'.mo-ai-row.user .mo-ai-bub{background:#2271b1;color:#fff;border-bottom-right-radius:4px;}' +
		'.mo-ai-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#9aa3ab;animation:mo-ai-blink 1.2s infinite both;}' +
		'.mo-ai-typing span:nth-child(2){animation-delay:.2s;}.mo-ai-typing span:nth-child(3){animation-delay:.4s;}' +
		'@keyframes mo-ai-blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}' +
		'.mo-ai-chips{padding:0 14px 8px;display:flex;flex-wrap:wrap;gap:6px;background:#f4f6f8;}' +
		'.mo-ai-chip{background:#fff;border:1px solid #c9d3dc;color:#2271b1;border-radius:14px;padding:5px 11px;font-size:12.5px;cursor:pointer;}' +
		'.mo-ai-chip:hover{background:#eef4fb;}' +
		'.mo-ai-foot{display:flex;gap:8px;padding:10px;border-top:1px solid #e4e7eb;background:#fff;}' +
		'.mo-ai-input{flex:1;border:1px solid #c9d3dc;border-radius:20px;padding:9px 14px;font-size:13.5px;outline:none;}' +
		'.mo-ai-input:focus{border-color:#2271b1;box-shadow:0 0 0 2px rgba(34,113,177,.15);}' +
		'.mo-ai-send{background:#2271b1;color:#fff;border:0;border-radius:50%;width:38px;height:38px;flex:0 0 38px;cursor:pointer;font-size:16px;}' +
		'.mo-ai-send:hover{background:#135e96;}.mo-ai-send:disabled{opacity:.5;cursor:default;}';
	document.head.appendChild( style );

	var fab = document.createElement( 'button' );
	fab.id = 'mo-ai-fab';
	fab.type = 'button';
	fab.innerHTML = '<span>✨</span><span>' + esc( NAME ) + '</span>';

	var panel = document.createElement( 'div' );
	panel.id = 'mo-ai-panel';
	panel.innerHTML =
		'<div class="mo-ai-head"><span class="mo-ai-dot"></span><b>' + esc( NAME ) + '</b>' +
		'<span class="mo-ai-beta">Beta</span><button type="button" class="mo-ai-clear">' + esc( cfg.clearLabel || '清除' ) + '</button></div>' +
		'<div class="mo-ai-msgs"></div>' +
		'<div class="mo-ai-chips"></div>' +
		'<div class="mo-ai-foot"><input type="text" class="mo-ai-input" placeholder="' + esc( cfg.placeholder || '' ) + '">' +
		'<button type="button" class="mo-ai-send" title="' + esc( cfg.sendLabel || '送出' ) + '">➤</button></div>';

	document.body.appendChild( fab );
	document.body.appendChild( panel );

	var msgs = panel.querySelector( '.mo-ai-msgs' );
	var chips = panel.querySelector( '.mo-ai-chips' );
	var input = panel.querySelector( '.mo-ai-input' );
	var send = panel.querySelector( '.mo-ai-send' );
	var busy = false;
	var started = false;

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s == null ? '' : String( s );
		return d.innerHTML;
	}

	function reset() {
		msgs.innerHTML = '';
		started = false;
		if ( GREETING ) {
			add_msg( GREETING, 'bot' );
		}
		render_chips();
	}

	function render_chips() {
		chips.innerHTML = '';
		EXAMPLES.forEach( function ( ex ) {
			var c = document.createElement( 'button' );
			c.type = 'button';
			c.className = 'mo-ai-chip';
			c.textContent = ex;
			c.addEventListener( 'click', function () {
				input.value = ex;
				ask();
			} );
			chips.appendChild( c );
		} );
	}

	function add_msg( text, who ) {
		var row = document.createElement( 'div' );
		row.className = 'mo-ai-row ' + who;
		var bubble;
		if ( who === 'bot' ) {
			var av = document.createElement( 'div' );
			av.className = 'mo-ai-av';
			av.textContent = '✨';
			row.appendChild( av );
		}
		bubble = document.createElement( 'div' );
		bubble.className = 'mo-ai-bub';
		bubble.textContent = text;
		row.appendChild( bubble );
		msgs.appendChild( row );
		msgs.scrollTop = msgs.scrollHeight;
		return bubble;
	}

	function add_typing() {
		var row = document.createElement( 'div' );
		row.className = 'mo-ai-row bot';
		row.innerHTML = '<div class="mo-ai-av">✨</div><div class="mo-ai-bub mo-ai-typing"><span></span><span></span><span></span></div>';
		msgs.appendChild( row );
		msgs.scrollTop = msgs.scrollHeight;
		return row;
	}

	function ask() {
		var text = ( input.value || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		if ( ! started ) {
			started = true;
			chips.style.display = 'none';
		}
		busy = true;
		send.disabled = true;
		input.value = '';
		add_msg( text, 'user' );
		var typing = add_typing();

		apiFetch( { path: '/mo-ectools/v1/ai-chat', method: 'POST', data: { message: text } } )
			.then( function ( r ) {
				typing.remove();
				add_msg(
					r && r.reply ? r.reply : ( r && r.error ? '⚠ ' + r.error : ( cfg.emptyReply || '（無回覆）' ) ),
					'bot'
				);
			} )
			.catch( function ( e ) {
				typing.remove();
				add_msg( '⚠ ' + ( e && e.message ? e.message : ( cfg.errorPrefix || '發生錯誤' ) ), 'bot' );
			} )
			.finally( function () {
				busy = false;
				send.disabled = false;
				input.focus();
			} );
	}

	fab.addEventListener( 'click', function () {
		var open = panel.style.display !== 'flex';
		panel.style.display = open ? 'flex' : 'none';
		if ( open ) {
			if ( msgs.children.length === 0 ) {
				reset();
			}
			input.focus();
		}
	} );
	panel.querySelector( '.mo-ai-clear' ).addEventListener( 'click', function () {
		chips.style.display = 'flex';
		reset();
	} );
	send.addEventListener( 'click', ask );
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			ask();
		}
	} );
} )( window.wp );
