// WC settings 沒提供 wrapper hook，用 JS 把 h2 + p + form-table 三組元素打包成 .mowp-section-card
(function(){
	var STORAGE_KEY = 'mowp_settings_collapsed_v1';
	function loadCollapsed(){
		try { return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {}; } catch(e){ return {}; }
	}
	function saveCollapsed(state){
		try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch(e){}
	}
	function group(){
		var collapsedState = loadCollapsed();
		var heads = document.querySelectorAll('#mainform h2');
		heads.forEach(function(h2, idx){
			if(h2.classList.contains('screen-reader-text') || h2.closest('.mowp-section-card') || h2.closest('.mowp-intro') || h2.closest('.mowp-subsection-banner')) return;
			// 把 h2 內的文字節點包進 span — accent line 跟著文字寬度延伸
			if(!h2.querySelector('.mo-h2-text')){
				var span = document.createElement('span');
				span.className = 'mo-h2-text';
				while(h2.firstChild) span.appendChild(h2.firstChild);
				h2.appendChild(span);
			}
			// chevron icon for collapse indicator
			if(!h2.querySelector('.mo-h2-chevron')){
				var chev = document.createElement('span');
				chev.className = 'mo-h2-chevron';
				chev.setAttribute('aria-hidden', 'true');
				h2.appendChild(chev);
			}
			var card = document.createElement('div');
			card.className = 'mowp-section-card';
			// 用 heading 文字當 stable key（i18n 後仍同名）
			var key = h2.querySelector('.mo-h2-text').textContent.trim();
			card.setAttribute('data-mo-key', key);
			if(collapsedState[key]) card.classList.add('is-collapsed');
			h2.parentNode.insertBefore(card, h2);
			card.appendChild(h2);
			// click toggle + a11y
			h2.setAttribute('role','button');
			h2.setAttribute('tabindex','0');
			h2.setAttribute('aria-expanded', collapsedState[key] ? 'false' : 'true');
			function toggle(){
				card.classList.toggle('is-collapsed');
				var nowCollapsed = card.classList.contains('is-collapsed');
				h2.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
				var st = loadCollapsed();
				if(nowCollapsed) st[key] = 1; else delete st[key];
				saveCollapsed(st);
			}
			h2.addEventListener('click', toggle);
			h2.addEventListener('keydown', function(e){
				if(e.key === 'Enter' || e.key === ' '){
					e.preventDefault();
					toggle();
				}
			});
			var next = card.nextElementSibling;
			while(next){
				var isDesc  = (next.tagName === 'P' || next.tagName === 'DIV') && !next.classList.contains('mowp-section-card');
				var isTable = next.tagName === 'TABLE' && /\bform-table\b/.test(next.className);
				if(!isDesc && !isTable) break;
				var temp = next.nextElementSibling;
				card.appendChild(next);
				next = temp;
			}
		});
	}
	// 批次列印介面 — 兩個 checkbox 互斥（XOR：勾一個自動取消另一個）
	function bindMutualExclusion(){
		var inputs = document.querySelectorAll('#mo_shipping_bulk_print_mode_basic, #mo_shipping_bulk_print_mode_advanced');
		if(inputs.length < 2) return;
		inputs.forEach(function(input){
			input.addEventListener('change', function(){
				if(!this.checked) return;
				inputs.forEach(function(other){
					if(other !== input) other.checked = false;
				});
			});
		});
	}
	function init(){ group(); bindMutualExclusion(); }
	if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
