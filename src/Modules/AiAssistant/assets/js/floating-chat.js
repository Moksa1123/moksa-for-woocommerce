/**
 * AI 助手浮動對話窗 — 後台右下角浮動按鈕,點開聊天,訊息經 REST(mo-ectools/v1/ai-chat)
 * 走 Agent agentic 迴圈(AI 用我們的查號 ability 查訂單後回答)。
 */
( function ( wp ) {
	if ( ! wp || ! wp.apiFetch || ! document.body ) {
		return;
	}
	var apiFetch = wp.apiFetch;

	var btn = document.createElement( 'button' );
	btn.type = 'button';
	btn.textContent = '💬 AI 助手';
	btn.style.cssText =
		'position:fixed;right:20px;bottom:20px;z-index:99998;background:#2271b1;color:#fff;border:0;border-radius:20px;padding:10px 16px;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.2);font-size:14px;';

	var panel = document.createElement( 'div' );
	panel.style.cssText =
		'position:fixed;right:20px;bottom:70px;z-index:99999;width:340px;max-width:92vw;height:460px;display:none;flex-direction:column;background:#fff;border:1px solid #ccd0d4;border-radius:10px;box-shadow:0 6px 24px rgba(0,0,0,.18);overflow:hidden;';
	panel.innerHTML =
		'<div style="background:#2271b1;color:#fff;padding:10px 14px;font-weight:600;">AI 助手 <span style="font-weight:400;font-size:12px;opacity:.85;">Beta</span></div>' +
		'<div class="mo-ai-msgs" style="flex:1;overflow-y:auto;padding:12px;font-size:13px;line-height:1.6;background:#f6f7f7;"></div>' +
		'<div style="display:flex;border-top:1px solid #e2e4e7;"><input type="text" class="mo-ai-input" placeholder="例如：查發票號 JT12202411" style="flex:1;border:0;padding:10px 12px;font-size:13px;outline:none;"><button type="button" class="mo-ai-send button button-primary" style="border-radius:0;border:0;">送出</button></div>';

	document.body.appendChild( btn );
	document.body.appendChild( panel );

	var msgs = panel.querySelector( '.mo-ai-msgs' );
	var input = panel.querySelector( '.mo-ai-input' );
	var send = panel.querySelector( '.mo-ai-send' );
	var busy = false;

	btn.addEventListener( 'click', function () {
		panel.style.display = panel.style.display === 'none' ? 'flex' : 'none';
		if ( panel.style.display === 'flex' ) {
			input.focus();
		}
	} );

	function add_msg( text, who ) {
		var row = document.createElement( 'div' );
		row.style.cssText = 'margin:6px 0;display:flex;' + ( who === 'user' ? 'justify-content:flex-end;' : '' );
		var bubble = document.createElement( 'div' );
		bubble.textContent = text;
		bubble.style.cssText =
			'max-width:80%;padding:8px 11px;border-radius:10px;white-space:pre-wrap;word-break:break-word;' +
			( who === 'user' ? 'background:#2271b1;color:#fff;' : 'background:#fff;border:1px solid #e2e4e7;color:#1d2327;' );
		row.appendChild( bubble );
		msgs.appendChild( row );
		msgs.scrollTop = msgs.scrollHeight;
		return bubble;
	}

	function ask() {
		var text = ( input.value || '' ).trim();
		if ( ! text || busy ) {
			return;
		}
		busy = true;
		input.value = '';
		add_msg( text, 'user' );
		var thinking = add_msg( '查詢中…', 'bot' );

		apiFetch( { path: '/mo-ectools/v1/ai-chat', method: 'POST', data: { message: text } } )
			.then( function ( r ) {
				thinking.textContent = r && r.reply
					? r.reply
					: ( r && r.error ? '⚠ ' + r.error : '（無回覆）' );
			} )
			.catch( function ( e ) {
				thinking.textContent = '⚠ ' + ( e && e.message ? e.message : '發生錯誤' );
			} )
			.finally( function () {
				busy = false;
				input.focus();
				msgs.scrollTop = msgs.scrollHeight;
			} );
	}

	send.addEventListener( 'click', ask );
	input.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Enter' ) {
			e.preventDefault();
			ask();
		}
	} );
} )( window.wp );
