// WC settings 沒提供 wrapper hook，用 JS 把 h2 + p + form-table 三組元素打包成 .moksafowo-section-card
(function(){
	var STORAGE_KEY = 'moksafowo_settings_collapsed_v1';
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
			if(h2.classList.contains('screen-reader-text') || h2.closest('.moksafowo-section-card') || h2.closest('.moksafowo-intro') || h2.closest('.moksafowo-subsection-banner')) return;
			// 把 h2 內的文字節點包進 span — accent line 跟著文字寬度延伸
			if(!h2.querySelector('.moksafowo-h2-text')){
				var span = document.createElement('span');
				span.className = 'moksafowo-h2-text';
				while(h2.firstChild) span.appendChild(h2.firstChild);
				h2.appendChild(span);
			}
			// chevron icon for collapse indicator
			if(!h2.querySelector('.moksafowo-h2-chevron')){
				var chev = document.createElement('span');
				chev.className = 'moksafowo-h2-chevron';
				chev.setAttribute('aria-hidden', 'true');
				h2.appendChild(chev);
			}
			var card = document.createElement('div');
			card.className = 'moksafowo-section-card';
			// 用 heading 文字當 stable key（i18n 後仍同名）
			var key = h2.querySelector('.moksafowo-h2-text').textContent.trim();
			card.setAttribute('data-moksafowo-key', key);
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
				var isDesc  = (next.tagName === 'P' || next.tagName === 'DIV') && !next.classList.contains('moksafowo-section-card');
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
		var inputs = document.querySelectorAll('#moksafowo_shipping_bulk_print_mode_basic, #moksafowo_shipping_bulk_print_mode_advanced');
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
	// 區塊總開關關掉時，把同一區其餘設定變灰 —— 讓人一眼看出「這些現在不會生效」。
	// 真正的停用在伺服器端（AdvancedSections::is_on），這裡純粹是視覺提示，
	// 所以不 disable 欄位：disable 掉的 checkbox 不會送出，儲存時會被當成取消勾選。
	function bindSectionMasters(){
		var masters = document.querySelectorAll('input.moksafowo-section-master[type="checkbox"]');
		masters.forEach(function(master){
			var table = master.closest('table.form-table');
			if(!table) return;
			var ownRow = master.closest('tr');
			var rows = Array.prototype.filter.call(table.querySelectorAll('tr'), function(tr){
				return tr !== ownRow;
			});
			if(!rows.length) return;

			var apply = function(){
				rows.forEach(function(tr){
					tr.classList.toggle('moksafowo-section-off', !master.checked);
				});
			};
			master.addEventListener('change', apply);
			apply();
		});
	}
	function init(){ group(); bindMutualExclusion(); bindSectionMasters(); }
	if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', init);
	else init();
})();
