/* Starter Live – modifica dei contenuti dal frontend. */
(function () {
	'use strict';

	var cfg = window.StarterLive;
	if (!cfg || !cfg.fields) { return; }

	// Su telefono la modifica dal frontend è disattivata (troppo scomoda): né pulsante, né modalità modifica. Stessa soglia del menu mobile di WordPress.
	var MOBILE = window.matchMedia('(max-width: 782px)');

	var T = cfg.i18n || {};
	var fields = cfg.fields;
	var changes = {}; // id -> nuovo valore
	var on = false;
	var saving = false;
	var hoverEl = null;
	var current = null; // { id, el, draft, read }
	var mediaOpen = false;

	/* ---------------------------------------------------------------- utilità */

	/* ---- sicurezza: ogni testo salvato che entra nell'editor o nell'anteprima passa di qui ---- */
	// Tag ammessi (come in un articolo di WordPress) con i soli attributi innocui; tutto il resto (script, iframe, onerror/onclick…) sparisce.
	var SAFE_TAGS = {
		A: ['href', 'target', 'rel', 'title'], STRONG: [], B: [], EM: [], I: [], U: [], BR: [], SUB: [], SUP: [], MARK: ['class'], SPAN: ['style', 'class'],
		P: [], UL: [], OL: [], LI: [], H1: [], H2: [], H3: [], H4: [], H5: [], H6: [], BLOCKQUOTE: [], FIGURE: [], FIGCAPTION: [], HR: [],
		IMG: ['src', 'alt', 'width', 'height', 'class'], DIV: ['class'], TABLE: [], THEAD: [], TBODY: [], TR: [], TH: [], TD: []
	};
	var DROP_WITH_CONTENT = /^(SCRIPT|STYLE|IFRAME|OBJECT|EMBED|TEMPLATE|NOSCRIPT|SVG|MATH|LINK|META|FORM|INPUT|BUTTON|TEXTAREA|SELECT)$/;

	function safeHtml(html) {
		// DOMParser crea un documento "inerte": nessun codice si esegue e nessuna immagine si carica finché non è stato ripulito.
		var doc = new DOMParser().parseFromString('<body>' + String(html) + '</body>', 'text/html');
		(function clean(node) {
			Array.prototype.slice.call(node.childNodes).forEach(function (n) {
				if (n.nodeType === 3) { return; }
				if (n.nodeType !== 1) { n.parentNode.removeChild(n); return; }
				var allowed = SAFE_TAGS[n.tagName];
				if (DROP_WITH_CONTENT.test(n.tagName)) { n.parentNode.removeChild(n); return; }
				if (!allowed) { // tag non ammesso: si tiene il testo, si toglie il tag
					clean(n);
					while (n.firstChild) { node.insertBefore(n.firstChild, n); }
					node.removeChild(n);
					return;
				}
				Array.prototype.slice.call(n.attributes).forEach(function (a) { if (allowed.indexOf(a.name.toLowerCase()) === -1) { n.removeAttribute(a.name); } });
				['href', 'src'].forEach(function (attr) {
					var v = (n.getAttribute(attr) || '').replace(/[\u0000-\u0020]+/g, '');
					if (v && !/^(https?:|mailto:|tel:|\/|#|\.)/i.test(v)) { n.removeAttribute(attr); }
				});
				var st = n.getAttribute('style');
				if (st !== null && !/^\s*text-decoration:\s*underline;?\s*$/i.test(st)) { n.removeAttribute('style'); }
				clean(n);
			});
		})(doc.body);
		return doc.body.innerHTML;
	}

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function h(tag, props, kids) {
		var n = document.createElement(tag);
		Object.keys(props || {}).forEach(function (k) {
			if (k === 'class') { n.className = props[k]; }
			else if (k === 'text') { n.textContent = props[k]; }
			else if (k.slice(0, 2) === 'on') { n.addEventListener(k.slice(2), props[k]); }
			else { n.setAttribute(k, props[k]); }
		});
		(kids || []).forEach(function (c) { if (c) { n.appendChild(c); } });
		return n;
	}

	function count() { return Object.keys(changes).length; }

	function sameValue(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

	/* ---------------------------------------------------------------- stile della pagina (fuori dallo shadow DOM) */

	var pageStyle = document.createElement('style');
	pageStyle.textContent =
		'html.starter-live-on [data-live-dirty]{outline:2px dashed #7c3aed!important;outline-offset:3px!important}' +
		'.media-modal{z-index:2147483600!important}.media-modal-backdrop{z-index:2147483500!important}' +
		'html.starter-live-lock,html.starter-live-lock body{overflow:hidden!important}html.starter-live-lock{scrollbar-gutter:stable}';
	document.head.appendChild(pageStyle);

	/* Con il popup aperto la pagina resta ferma: il popup è posizionato sull'elemento, scorrere lo staccherebbe.
	   Lo scroll fluido del tema (Lenis) si ferma e salta il body (data-lenis-prevent), così popup e libreria media scorrono da soli. */
	function lockScroll(locked) {
		document.documentElement.classList.toggle('starter-live-lock', locked);
		if (locked) { document.body.setAttribute('data-lenis-prevent', ''); } else { document.body.removeAttribute('data-lenis-prevent'); }
		if (window.starterLenis) { window.starterLenis[locked ? 'stop' : 'start'](); }
	}

	// Il video ha il file in <source>: il bersaglio vero è il <video>.
	document.querySelectorAll('source[data-live]').forEach(function (s) {
		var v = s.closest('video');
		if (v && !v.hasAttribute('data-live')) { v.setAttribute('data-live', s.getAttribute('data-live')); }
	});

	/* ---------------------------------------------------------------- interfaccia (shadow DOM) */

	var host = h('div', { id: 'starter-live-root' });
	host.style.cssText = 'all:initial;position:fixed;inset:0;z-index:2147483000;pointer-events:none;';
	var root = host.attachShadow({ mode: 'open' });

	root.innerHTML =
		'<style>' +
		':host{all:initial}' +
		'*{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;letter-spacing:0}' +
		'button{font:inherit;cursor:pointer}' +
		'.bar{position:fixed;left:50%;bottom:18px;transform:translateX(-50%);display:flex;align-items:center;gap:8px;pointer-events:auto;font-size:14px;color:#031428;max-width:calc(100vw - 24px)}' +
		'.bar.open{padding:6px;background:#fff;border:3px solid #031428;border-radius:999px;box-shadow:0 8px 30px rgba(3,20,40,.28)}' +
		'.bar button{border:3px solid transparent;border-radius:999px;padding:9px 16px;font-size:14px;font-weight:600;color:#031428;background:transparent;display:inline-flex;align-items:center;gap:8px;white-space:nowrap;transition:background-color .28s ease,transform .22s cubic-bezier(.2,.8,.2,1),box-shadow .28s ease}' +
		'.bar button:hover{background:rgba(3,20,40,.08)}' +
		'.bar .primary{background:#b7ff00;border-color:#031428;box-shadow:0 6px 18px rgba(3,20,40,.22)}' +
		'.bar.open .primary{border-width:2px;box-shadow:none}' +
		'.bar .primary:hover{background:#a6e800;transform:translateY(-2px);box-shadow:0 10px 22px rgba(3,20,40,.3)}' +
		'.bar.open .primary:hover{box-shadow:0 6px 14px rgba(3,20,40,.28)}' +
		'.bar .primary:active{transform:translateY(0);box-shadow:0 3px 8px rgba(3,20,40,.25)}' +
		'.bar button:focus-visible{outline:3px solid #031428;outline-offset:3px}' +
		'.bar .ic{display:inline-flex;align-items:center}' +
		'.bar .primary[disabled]{opacity:.6;cursor:wait;transform:none}' +
		'@media (max-width:480px){.bar.open{gap:4px;padding:4px}.bar button{padding:8px 12px;font-size:13px}.bar .count{padding:0 4px}.bar .count .cl,.bar .count.zero{display:none}}' +
		'@media (prefers-reduced-motion:reduce){.bar button{transition:none}.bar .primary:hover{transform:none}}' +
		'.bar .count{padding:0 6px;font-size:13px;font-weight:600;white-space:nowrap}' +
		'.hl{position:fixed;border:2px solid #7c3aed;background:rgba(124,58,237,.08);border-radius:4px;pointer-events:none;display:none}' +
		'.tag{position:fixed;background:#7c3aed;color:#fff;font-size:12px;font-weight:600;padding:4px 9px;border-radius:6px;pointer-events:none;display:none;max-width:60vw;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
		'.pop{position:fixed;width:360px;max-width:calc(100vw - 16px);background:#fff;color:#16131f;border-radius:14px;box-shadow:0 18px 60px rgba(0,0,0,.35);padding:16px;pointer-events:auto;display:none}' +
		'.pop h4{margin:0 0 10px;font-size:14px;font-weight:700}' +
		'.pop label{display:block;font-size:12px;font-weight:600;margin:10px 0 4px;color:#555}' +
		'.pop input[type=text],.pop input[type=number],.pop textarea,.pop .rich{width:100%;border:1.5px solid #cfc8e6;border-radius:9px;padding:9px 11px;font-size:15px;line-height:1.45;color:#16131f;background:#fff;outline:none}' +
		'.pop input[type=text]:focus,.pop input[type=number]:focus,.pop textarea:focus,.pop .rich:focus{border-color:#7c3aed;box-shadow:0 0 0 3px rgba(124,58,237,.18)}' +
		'.pop textarea{resize:vertical;min-height:84px}' +
		'.pop select{width:100%;border:1.5px solid #cfc8e6;border-radius:9px;padding:9px 11px;font-size:15px;color:#16131f;background:#fff}' +
		'.pop .rich{min-height:84px;max-height:260px;overflow:auto;white-space:pre-wrap}' +
		'.tb{display:flex;gap:4px;margin-bottom:6px}' +
		'.tb button{border:1px solid #d9d3ee;background:#faf9fe;border-radius:7px;padding:4px 10px;font-size:13px;color:#16131f}' +
		'.tb button:hover{background:#efeafc}' +
		'.chk{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:500;margin-top:10px;color:#333}' +
		'.gal{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:10px}' +
		'.gcell{position:relative;aspect-ratio:1;border-radius:9px;overflow:hidden;background:#f2f0f8}' +
		'.gcell img{width:100%;height:100%;object-fit:cover;display:block}' +
		'.gx{position:absolute;top:3px;right:3px;width:22px;height:22px;border:0;border-radius:50%;background:rgba(21,18,31,.8);color:#fff;font-size:15px;line-height:1;padding:0}' +
		'.gm{position:absolute;left:0;right:0;bottom:0;display:flex;justify-content:space-between;padding:3px}' +
		'.gm button{border:0;border-radius:5px;background:rgba(21,18,31,.75);color:#fff;font-size:10px;padding:2px 6px}' +
		'.btn[disabled]{opacity:.55;cursor:not-allowed}' +
		'.thumb.small{max-height:110px}' +
		'.pop .upl{margin:16px 0 10px}' +
		'.pop .rich.listed{margin:0;padding-left:30px;max-height:260px;overflow:auto;list-style:disc}' +
		'.pop .rich.listed li{margin:2px 0}' +
		'.icur{display:flex;align-items:center;gap:10px;margin-bottom:8px;font-size:14px;font-weight:600}' +
		'.icb{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:10px;background:#b7ff00;border:2px solid #031428;color:#031428}' +
		'.icur .none{color:#777;font-weight:500}' +
		'.itabs{display:flex;gap:4px;margin:8px 0}' +
		'.itabs button{border:1.5px solid #d9d3ee;background:#fff;border-radius:999px;padding:4px 11px;font-size:12px;color:#16131f}' +
		'.itabs button.on{background:#b7ff00;border-color:#031428;color:#031428;font-weight:600}' +
		'.ig{display:grid;grid-template-columns:repeat(8,1fr);gap:4px;max-height:230px;overflow:auto}' +
		'.ii{aspect-ratio:1;border:1.5px solid transparent;border-radius:8px;background:#f6f5fb;color:#031428;display:flex;align-items:center;justify-content:center;padding:0;transition:background-color .18s ease,border-color .18s ease}' +
		'.ii:hover,.ii.on{background:#b7ff00;border-color:#031428}' +
		'.thumb{width:100%;max-height:200px;object-fit:contain;background:#f2f0f8;border-radius:9px;display:block;margin-bottom:10px}' +
		'.file{padding:12px;background:#f2f0f8;border-radius:9px;font-size:13px;word-break:break-all;margin-bottom:10px}' +
		'.btn{border:1.5px solid #d9d3ee;background:#fff;color:#16131f;border-radius:9px;padding:9px 14px;font-size:14px;font-weight:600}' +
		'.btn:hover{background:#f5f2fd}' +
		'.btn.full{width:100%}' +
		'.btn.go{background:#7c3aed;border-color:#7c3aed;color:#fff}.btn.go:hover{background:#6d28d9}' +
		'.row{display:flex;gap:8px;justify-content:flex-end;margin-top:14px;align-items:center}' +
		'.row .sp{flex:1}.row .lnk{border:0;background:none;color:#7c3aed;font-size:13px;font-weight:600;padding:4px}' +
		'.hint{margin:10px 0 0;font-size:12px;color:#777}' +
		'.toast{position:fixed;left:50%;bottom:80px;transform:translateX(-50%);background:#15121f;color:#fff;padding:10px 16px;border-radius:10px;font-size:14px;pointer-events:none;display:none;box-shadow:0 8px 30px rgba(0,0,0,.3);max-width:90vw}' +
		'.toast.err{background:#b42318}' +
		'</style>' +
		'<div class="hl"></div><div class="tag"></div><div class="pop"></div><div class="toast"></div><div class="bar"></div>';

	var hl = root.querySelector('.hl');
	var tag = root.querySelector('.tag');
	var pop = root.querySelector('.pop');
	var toast = root.querySelector('.toast');
	var bar = root.querySelector('.bar');

	function showToast(msg, isErr, ms) {
		toast.textContent = msg;
		toast.className = 'toast' + (isErr ? ' err' : '');
		toast.style.display = 'block';
		clearTimeout(showToast.t);
		showToast.t = setTimeout(function () { toast.style.display = 'none'; }, ms || 3000);
	}

	/* ---------------------------------------------------------------- barra in basso */

	/* Voce "Modifica contenuti" nella barra admin: come il pulsante in basso, diventa "Salva e chiudi" quando la modalità è attiva. */
	function renderAdminItem() {
		var a = document.querySelector('#wp-admin-bar-starter-live > a');
		if (!a) { return; }
		a.textContent = !on ? T.open : (saving ? T.saving : '✓ ' + T.saveClose);
	}

	function renderBar() {
		renderAdminItem();
		bar.innerHTML = '';
		bar.className = 'bar' + (on ? ' open' : '');
		if (!on) {
			bar.appendChild(h('button', { class: 'primary', onclick: toggle }, [document.createTextNode(T.open)]));
			return;
		}
		var n = count();
		var cnt = h('span', { class: 'count' + (n ? '' : ' zero') });
		if (n) { cnt.appendChild(h('b', { text: String(n) })); cnt.appendChild(h('span', { class: 'cl', text: ' ' + (n === 1 ? T.change : T.changes) })); } else { cnt.textContent = T.noChanges; }
		bar.appendChild(cnt);
		bar.appendChild(h('button', { text: T.cancel, onclick: cancelAll }));
		var save = h('button', { class: 'primary', onclick: saveAndClose }, [h('span', { class: 'ic', text: saving ? '' : '✓' }), document.createTextNode(saving ? T.saving : T.saveClose)]);
		if (saving) { save.setAttribute('disabled', ''); }
		bar.appendChild(save);
	}

	function toggle() {
		on = !on;
		document.documentElement.classList.toggle('starter-live-on', on);
		if (!on) { closePopup(); hideHl(); }
		renderBar();
	}

	function cancelAll() {
		if (count() && !window.confirm(T.discard)) { return; }
		if (count()) { window.removeEventListener('beforeunload', guard); location.reload(); return; }
		toggle();
	}

	function saveAndClose() {
		closePopup();
		var ids = Object.keys(changes);
		if (!ids.length) { toggle(); return; }

		saving = true;
		renderBar();

		var payload = ids.map(function (id) {
			var f = fields[id];
			return { rev: (cfg.revs && cfg.revs[f.post]) || '', post: f.post || 0, term: f.term || 0, tax: f.tax || '', path: f.path, block: f.block, src: f.src, key: f.key || '', field: f.field || '', idx: typeof f.idx === 'number' ? f.idx : null, t: f.t, value: changes[id] };
		});

		fetch(cfg.rest, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify({ changes: payload })
		}).then(function (r) {
			return r.json().then(function (j) { return { ok: r.ok, j: j }; });
		}).then(function (res) {
			if (res.ok && res.j.saved && !(res.j.errors && res.j.errors.length)) {
				try { sessionStorage.setItem('starterLiveSaved', '1'); } catch (e) { /* niente */ }
				window.removeEventListener('beforeunload', guard);
				location.reload();
				return;
			}
			throw new Error((res.j && (res.j.message || (res.j.errors || []).join(' '))) || 'errore');
		}).catch(function (e) {
			saving = false;
			renderBar();
			showToast(T.error + ' ' + e.message, true, 7000);
		});
	}

	function guard(e) {
		if (count() && !saving) { e.preventDefault(); e.returnValue = ''; }
	}
	window.addEventListener('beforeunload', guard);

	/* ---------------------------------------------------------------- selezione degli elementi */

	var TEXTLIKE = { text: 1, rich: 1, link: 1, icon: 1, list: 1, shortcode: 1, number: 1, select: 1 };

	function visible(n) {
		var cs = getComputedStyle(n);
		return cs.display !== 'none' && cs.visibility !== 'hidden' && parseFloat(cs.opacity) > 0.01;
	}

	/* Interfacce che NON sono contenuto della pagina: finestra della Libreria Media, barra admin, finestre di dialogo (es. banner cookie),
	   menu del sito, moduli e pulsanti. Se una di queste è l'elemento più in alto sotto il puntatore, il plugin non interviene mai:
	   il clic deve funzionare come sempre, anche quando sotto c'è un elemento modificabile. */
	var UI_SELECTOR = '.media-modal, .media-modal-backdrop, #wpadminbar, [role="dialog"], [aria-modal="true"], [class*="cky-"], #site-header, .site-header, nav, button, input, select, textarea, label, summary';

	function pick(x, y) {
		if (mediaOpen) { return null; }

		// 1) quello che sta davvero sotto il puntatore (l'elemento più in alto)
		var hit = null, top = null;
		var list = document.elementsFromPoint(x, y);
		for (var i = 0; i < list.length; i++) {
			if (list[i] === host) { continue; }
			if (!top) { top = list[i]; }
			var t = list[i].closest('[data-live]');
			if (t) { hit = t; break; }
		}
		// L'elemento più in alto è un'interfaccia (e non fa parte di un contenuto modificabile): non si interviene.
		if (top && top.closest && top.closest(UI_SELECTOR) && !top.closest('[data-live]')) { return null; }
		if (hit && TEXTLIKE[(fields[hit.getAttribute('data-live')] || {}).t]) { return hit; }

		// 2) testi, link e icone hanno la precedenza su foto, gallery e video: spesso sono sopra l'immagine ma non ricevono il puntatore
		//    (es. il titolo grande di una hero con slider). Si cerca per posizione e si sceglie il più piccolo.
		var best = null, bestArea = Infinity, any = null, anyArea = Infinity;
		document.querySelectorAll('[data-live]').forEach(function (n) {
			var r = n.getBoundingClientRect();
			if (r.width < 2 || r.height < 2 || x < r.left || x > r.right || y < r.top || y > r.bottom || !visible(n)) { return; }
			var area = r.width * r.height;
			if (TEXTLIKE[(fields[n.getAttribute('data-live')] || {}).t]) {
				if (area < bestArea) { best = n; bestArea = area; }
			} else if (area < anyArea) { any = n; anyArea = area; }
		});
		return best || hit || any;
	}

	function placeHl(el) {
		if (!el || !document.contains(el)) { hideHl(); return; }
		var r = el.getBoundingClientRect();
		hl.style.cssText = 'display:block;left:' + r.left + 'px;top:' + r.top + 'px;width:' + r.width + 'px;height:' + r.height + 'px';
		var f = fields[el.getAttribute('data-live')];
		tag.textContent = f ? f.label : '';
		tag.style.display = f ? 'block' : 'none';
		var ty = r.top - 28;
		tag.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 120)) + 'px';
		tag.style.top = (ty < 4 ? r.top + 4 : ty) + 'px';
	}

	function hideHl() { hl.style.display = 'none'; tag.style.display = 'none'; }

	var raf = 0;
	document.addEventListener('mousemove', function (e) {
		if (!on || current) { return; }
		var x = e.clientX, y = e.clientY;
		cancelAnimationFrame(raf);
		raf = requestAnimationFrame(function () {
			hoverEl = pick(x, y);
			if (hoverEl) { placeHl(hoverEl); } else { hideHl(); }
			document.documentElement.style.cursor = hoverEl ? 'pointer' : '';
		});
	}, true);

	function refresh() {
		if (current) { placeHl(current.el); placePopup(); } else if (hoverEl) { placeHl(hoverEl); }
	}
	window.addEventListener('scroll', refresh, true);
	window.addEventListener('resize', refresh);

	document.addEventListener('click', function (e) {
		if (e.target === host || host.contains(e.target)) { return; }
		var adminBtn = e.target.closest && e.target.closest('#wp-admin-bar-starter-live > a');
		if (adminBtn && MOBILE.matches) { e.preventDefault(); return; }
		if (adminBtn) { e.preventDefault(); if (on) { if (!saving) { saveAndClose(); } } else { toggle(); } return; }
		if (!on) { return; }
		var t = pick(e.clientX, e.clientY);
		if (!t) { return; }
		e.preventDefault();
		e.stopPropagation();
		e.stopImmediatePropagation();
		openPopup(t);
	}, true);

	['mousedown', 'mouseup', 'pointerdown', 'pointerup', 'touchstart'].forEach(function (ev) {
		document.addEventListener(ev, function (e) {
			if (!on || host.contains(e.target) || e.target === host) { return; }
			if (pick(e.clientX || 0, e.clientY || 0)) { e.stopPropagation(); }
		}, true);
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && current && !mediaOpen) { closePopup(); }
	}, true);

	/* ---------------------------------------------------------------- applicare i valori alla pagina (anteprima) */

	function applyToDom(el, f, value) {
		// Spazio facoltativo (vuoto): appena si scrive qualcosa diventa un elemento normale; se si svuota torna a essere nascosto fuori dalla modalità modifica.
		if (f.t === 'text' || f.t === 'rich') {
			if (el.hasAttribute('data-live-empty') || el.__liveWasEmpty) {
				el.__liveWasEmpty = true;
				if (!String(value).replace(/<br\s*\/?>/gi, '').trim()) { el.setAttribute('data-live-empty', ''); } else { el.removeAttribute('data-live-empty'); }
			}
		}
		switch (f.t) {
			case 'text':
				if (f.map && el.tagName === 'IFRAME') { // indirizzo di una mappa: cambiano l'iframe e il link "Indicazioni stradali" del blocco
					var enc = encodeURIComponent(value);
					el.src = el.src.replace(/([?&]q=)[^&]*/, '$1' + enc);
					if (!el.__mapLinks) {
						var scope = el.closest('section') || document;
						el.__mapLinks = Array.prototype.map.call(scope.querySelectorAll('a[href*="destination="]'), function (a) { return [a, a.getAttribute('href')]; });
					}
					el.__mapLinks.forEach(function (pair) { pair[0].setAttribute('href', pair[1].replace(/(destination=)[^&]*/, '$1' + enc)); });
					break;
				}
				if (f.tn) { // il testo sta accanto a un'icona o a virgolette: si cambia solo il nodo di testo
					var prev = f.cur !== undefined ? f.cur : f.value;
					var node = textNodeWith(el, prev);
					if (node) { node.nodeValue = node.nodeValue.replace(prev, value); f.cur = value; }
					break;
				}
				if ((f.src === 'field' || f.src === 'metapost') && /<[a-z]/i.test(String(value))) { el.innerHTML = safeHtml(value); break; } // testo con grassetto / corsivo / sottolineato
				el.innerHTML = esc(value).replace(/\n/g, f.multiline ? '<br>' : ' ');
				break;
			case 'rich':
				el.innerHTML = safeHtml(value);
				break;
			case 'select':
				// Scelta da elenco: nella pagina compare l'etichetta (le scelte multiple unite da virgole, dalla seconda in minuscolo).
				var labels = [].concat(value).map(function (k) { return f.choices && f.choices[k] !== undefined ? f.choices[k] : k; });
				var shown = labels.map(function (s, i) { return i ? s.charAt(0).toLowerCase() + s.slice(1) : s; }).join(', ');
				var before = f.cur !== undefined ? f.cur : [].concat(f.value).map(function (k) { return f.choices && f.choices[k] !== undefined ? f.choices[k] : k; }).map(function (s, i) { return i ? s.charAt(0).toLowerCase() + s.slice(1) : s; }).join(', ');
				var selNode = before ? textNodeWith(el, before) : null;
				if (selNode) { selNode.nodeValue = selNode.nodeValue.replace(before, shown); } else { el.textContent = shown; }
				f.cur = shown;
				break;
			case 'number':
				// Il valore è scritto accanto ad altre parole o simboli ("€ 1.500.000", "5+ locali"): si cambia solo il numero.
				if (String(value).trim() === '' || isNaN(Number(String(value).replace(',', '.')))) { break; }
				var formatted = Number(String(value).replace(',', '.')).toLocaleString('it-IT', { maximumFractionDigits: 2 });
				var numNode = (function walk(n) { for (var c = n.firstChild; c; c = c.nextSibling) { if (c.nodeType === 3 && /\d/.test(c.nodeValue)) { return c; } if (c.nodeType === 1) { var r = walk(c); if (r) { return r; } } } return null; })(el);
				if (numNode) { numNode.nodeValue = numNode.nodeValue.replace(/\d[\d.,]*/, formatted); }
				break;
			case 'link':
				var nodes = el.querySelectorAll('.button__text');
				if (f.notext) {
					/* testo non visibile: si cambia solo l'indirizzo */
				} else if (nodes.length) {
					nodes.forEach(function (n) { n.textContent = value.title; });
				} else {
					var tn = Array.prototype.find.call(el.childNodes, function (c) { return c.nodeType === 3 && c.nodeValue.trim(); });
					if (tn) { tn.nodeValue = value.title; } else if (!el.children.length) { el.textContent = value.title; }
				}
				el.setAttribute('href', value.url);
				if (value.target === '_blank') { el.setAttribute('target', '_blank'); el.setAttribute('rel', 'noopener'); } else { el.removeAttribute('target'); el.removeAttribute('rel'); }
				break;
			case 'shortcode':
				el.innerHTML = '<p style="opacity:.6;margin:0">' + esc(T.previewLoading) + '</p>';
				fetch(cfg.shortcodes.rest, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }, body: JSON.stringify({ code: value }) })
					.then(function (r) { return r.json(); })
					.then(function (d) { if (d && typeof d.html === 'string') { el.innerHTML = d.html; } else { showToast((d && d.message) || T.error, true); } });
				break;
			case 'list':
				if (f.lines) { // elenco in un campo di testo: ogni voce mantiene la sua spunta (o altra decorazione) copiata dalla prima voce
					var firstLi = el.querySelector('li');
					var deco = firstLi && firstLi.firstElementChild ? firstLi.firstElementChild.outerHTML : '';
					el.innerHTML = value.map(function (v) { return '<li>' + deco + esc(v) + '</li>'; }).join('');
					break;
				}
				el.innerHTML = safeHtml(value.map(function (v) { return '<li>' + v + '</li>'; }).join(''));
				break;
			case 'icon':
				applyIcon(el, value);
				break;
			case 'image':
			case 'file':
				applyMedia(el, value);
				break;
		}
	}

	/* ---- icone (libreria del tema) ---- */
	var iconLib = null, iconWait = [];

	function loadIcons(cb) {
		if (iconLib) { cb(iconLib); return; }
		iconWait.push(cb);
		if (iconWait.length > 1) { return; }
		fetch(cfg.icons, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
			.then(function (r) { return r.json(); })
			.then(function (d) { iconLib = d; iconLib.names = Object.keys(d.icons); iconWait.splice(0).forEach(function (f) { f(iconLib); }); });
	}

	var PAINT_ATTRS = ['fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin'];

	/* Attributi di disegno (viewBox + colori/tratto) di un'icona: libreria, logo (pieno) o SVG caricato dall'utente. */
	function iconFrame(name) {
		var custom = iconLib.svgs && iconLib.svgs[name];
		if (custom) { return { vb: custom.vb, paint: custom.paint }; }
		if (name.indexOf('brand-') === 0) { return { vb: '0 0 24 24', paint: { fill: 'currentColor', stroke: 'none' } }; }
		return { vb: '0 0 24 24', paint: { fill: 'none', stroke: 'currentColor', 'stroke-width': '1.75', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } };
	}

	function iconSvg(name, size) {
		var fr = iconFrame(name);
		var paint = Object.keys(fr.paint).map(function (k) { return k + '="' + String(fr.paint[k]).replace(/"/g, '&quot;') + '"'; }).join(' ');
		return '<svg width="' + size + '" height="' + size + '" viewBox="' + fr.vb + '" ' + paint + ' aria-hidden="true">' + (iconLib.icons[name] || '') + '</svg>';
	}

	function applyIcon(el, name) {
		if (!name) { el.style.display = 'none'; return; }
		if (!iconLib || !iconLib.icons[name]) { return; }
		var fr = iconFrame(name);
		el.style.display = '';
		el.innerHTML = iconLib.icons[name];
		el.setAttribute('class', (el.getAttribute('class') || 'icon').replace(/icon--[a-z0-9-]+/, 'icon--' + name));
		el.setAttribute('viewBox', fr.vb);
		PAINT_ATTRS.forEach(function (a) { el.removeAttribute(a); });
		Object.keys(fr.paint).forEach(function (k) { el.setAttribute(k, fr.paint[k]); });
	}

	function textNodeWith(el, text) {
		var w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null), n;
		while ((n = w.nextNode())) { if (n.nodeValue.indexOf(text) !== -1) { return n; } }
		return null;
	}

	function applyMedia(el, m) {
		if (!m || !m.url) { return; }
		var tagName = el.tagName.toLowerCase();
		if (tagName === 'img') {
			el.removeAttribute('srcset'); el.removeAttribute('sizes'); el.src = m.url;
		} else if (tagName === 'video') {
			el.src = m.url;
			el.querySelectorAll('source').forEach(function (s) { s.remove(); });
			el.load();
			var pl = el.play && el.play(); if (pl && pl.catch) { pl.catch(function () {}); }
		} else if (/url\(/.test(el.getAttribute('style') || '')) {
			el.style.backgroundImage = 'url("' + m.url + '")';
		} else if (el.tagName === 'A') {
			el.href = m.url;
		}
	}

	var originals = {}; // id -> { html, attrs } per il ripristino
	var ATTRS = ['style', 'src', 'srcset', 'sizes', 'href', 'target', 'rel', 'poster', 'class', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'viewBox', 'data-live-empty', 'alt'];

	function remember(el, id) {
		if (originals[id]) { return; }
		var o = { html: el.innerHTML };
		ATTRS.forEach(function (a) { o[a] = el.getAttribute(a); });
		originals[id] = o;
	}

	function restore(el, id) {
		var o = originals[id];
		if (!o) { return; }
		el.innerHTML = o.html;
		if (el.__mapLinks) { el.__mapLinks.forEach(function (pair) { pair[0].setAttribute('href', pair[1]); }); }
		if (fields[id]) { delete fields[id].cur; }
		ATTRS.forEach(function (a) {
			if (o[a] === null) { el.removeAttribute(a); } else { el.setAttribute(a, o[a]); }
		});
		if (el.tagName === 'VIDEO') { el.load(); }
	}

	function setChange(id, el, value) {
		var f = fields[id];
		remember(el, id);
		changes[id] = value;
		applyToDom(el, f, value);
		el.setAttribute('data-live-dirty', '');
		// Indirizzo: lo stesso valore è scritto nel testo e nella mappa, cambiano insieme.
		if (f.map) {
			document.querySelectorAll('[data-live="' + id + '"]').forEach(function (n, i) {
				if (n !== el) { remember(n, id + '~' + i); applyToDom(n, f, value); n.setAttribute('data-live-dirty', ''); }
			});
		}
		renderBar();
	}

	function markGallery(id, on) {
		document.querySelectorAll('[data-live="' + id + '"]').forEach(function (n) {
			if (on) { n.setAttribute('data-live-dirty', ''); } else { n.removeAttribute('data-live-dirty'); }
		});
	}

	function clearChange(id, el) {
		delete changes[id];
		delete committedUrl[id];
		if (fields[id].poster) { delete committedPoster[fields[id].poster]; }
		if (fields[id].t === 'gallery' && fields[id].max !== 1) { restoreGallery(id); delete galState[id]; markGallery(id, false); renderBar(); return; }
		restore(el, id);
		el.removeAttribute('data-live-dirty');
		if (fields[id].map) {
			document.querySelectorAll('[data-live="' + id + '"]').forEach(function (n, i) { if (n !== el) { restore(n, id + '~' + i); n.removeAttribute('data-live-dirty'); } });
		}
		renderBar();
	}

	/* ---------------------------------------------------------------- popup di modifica */

	function placePopup() {
		if (!current) { return; }
		var r = current.el.getBoundingClientRect();
		var pw = pop.offsetWidth || 360, ph = pop.offsetHeight || 200;
		var limit = window.innerHeight - 84; // lascia spazio alla barra in basso
		var left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
		var top = r.bottom + 10;
		if (top + ph > limit) { top = r.top - ph - 10; }
		if (top < 8) { top = Math.max(8, Math.min(limit - ph, r.top + 12)); }
		pop.style.left = left + 'px';
		pop.style.top = top + 'px';
	}

	/* ---- editor dei testi: barra Grassetto / Corsivo / Sottolineato, uguale per tutti i testi ---- */
	function fmtButton(label, cmd, title, style) {
		return h('button', { type: 'button', text: label, title: title, style: style || '', onmousedown: function (e) { e.preventDefault(); document.execCommand(cmd); } });
	}

	function textToolbar(withLink) {
		var kids = [
			fmtButton('B', 'bold', T.bold, 'font-weight:700'),
			fmtButton('I', 'italic', T.italic, 'font-style:italic'),
			fmtButton('U', 'underline', T.underline, 'text-decoration:underline')
		];
		if (withLink) {
			kids.push(h('button', { type: 'button', text: '🔗', title: T.link, onmousedown: function (e) {
				e.preventDefault();
				var sel = window.getSelection(); var saved = sel.rangeCount ? sel.getRangeAt(0).cloneRange() : null;
				var url = window.prompt(T.askUrl, 'https://');
				if (saved) { sel.removeAllRanges(); sel.addRange(saved); }
				if (url) { document.execCommand('createLink', false, url); } else if (url === '') { document.execCommand('unlink'); }
			} }));
		}
		return h('div', { class: 'tb' }, kids);
	}

	/* Contenuto di un editor di testo: HTML se ha formattazione, altrimenti testo (così "&" e "<" restano com'erano). */
	function setEditorContent(ed, value) {
		if (/<[a-z][\s\S]*>/i.test(String(value))) { ed.innerHTML = safeHtml(value); } else { ed.textContent = value; }
	}

	/* HTML pulito di un editor: il sottolineato resta, gli altri stili e i <div> di Invio spariscono; senza formattazione torna testo semplice. */
	function cleanEditorHtml(ed, multiline) {
		var c = ed.cloneNode(true);
		c.querySelectorAll('[style]').forEach(function (n) {
			if (/underline/i.test(n.getAttribute('style') || '')) {
				var u = document.createElement('u');
				while (n.firstChild) { u.appendChild(n.firstChild); }
				n.parentNode.replaceChild(u, n);
			} else { n.removeAttribute('style'); }
		});
		var html = c.innerHTML.replace(/<div>/gi, '<br>').replace(/<\/div>/gi, '').replace(/&nbsp;/g, ' ').replace(/(<br\s*\/?>)+$/i, '');
		if (!multiline) { html = html.replace(/<br\s*\/?>/gi, ' '); }
		if (!/<[a-z]/i.test(html)) { var t = document.createElement('textarea'); t.innerHTML = html; html = t.value; }
		return html;
	}

	/* ---- anteprima immediata delle immagini (prima di "Applica" e di "Salva e chiudi") ---- */
	var galState = {};      // id gallery -> { list, urls } già applicati (anteprima confermata)
	var committedUrl = {};  // id campo -> url dell'immagine confermata
	var committedPoster = {}; // id copertina -> url confermato
	var snapGal = new WeakMap();
	var SNAP = ['src', 'srcset', 'sizes', 'style'];

	function galleryEls(id) { return Array.prototype.slice.call(document.querySelectorAll('[data-live="' + id + '"]')); }

	function snapEl(n) {
		if (snapGal.has(n)) { return snapGal.get(n); }
		var o = { display: n.style.display };
		SNAP.forEach(function (a) { o[a] = n.getAttribute(a); });
		snapGal.set(n, o);
		return o;
	}

	/* Url "attuale" di un elemento di gallery (immagine o sfondo), preferendo quello originale se già modificato. */
	function elUrl(n) {
		var o = snapGal.get(n);
		if (n.tagName === 'IMG') { return o && o.src ? o.src : (n.currentSrc || n.src); }
		var m = /url\(["']?([^"')]+)["']?\)/.exec((o ? o.style : n.getAttribute('style')) || '');
		return m ? m[1] : '';
	}

	function previewGallery(id, list, urls) {
		galleryEls(id).forEach(function (n) {
			var i = parseInt(n.getAttribute('data-live-i'), 10);
			if (isNaN(i)) { return; }
			var o = snapEl(n), gid = list[i];
			if (gid === undefined) { n.style.display = 'none'; return; }   // foto tolta: quell'elemento sparisce
			n.style.display = o.display;
			if (urls[gid]) { applyMedia(n, { url: urls[gid] }); }
		});
	}

	function restoreGallery(id) {
		galleryEls(id).forEach(function (n) {
			var o = snapGal.get(n);
			if (!o) { return; }
			SNAP.forEach(function (a) { if (o[a] === null) { n.removeAttribute(a); } else { n.setAttribute(a, o[a]); } });
		});
	}

	function closePopup(commit) {
		// Chiusa senza confermare ("Annulla", Esc, altro elemento): l'anteprima provvisoria torna com'era.
		if (!commit && current && typeof current.cancel === 'function') { current.cancel(); }
		current = null;
		pop.style.display = 'none';
		pop.innerHTML = '';
		lockScroll(false);
	}

	function openPopup(el) {
		closePopup();
		var id = el.getAttribute('data-live');
		var f = fields[id];
		if (!f) { return; }

		var pickedPoster = null; // nuova copertina scelta (solo video)
		// Gallery che ammette una sola foto: si comporta come una foto singola (si sostituisce, non si aggiunge).
		var asSingle = f.t === 'gallery' && f.max === 1;
		var cancelFn = null;   // come annullare l'anteprima provvisoria di questo popup
		var galUrls = null;    // url delle foto della gallery (per l'anteprima)
		var start = Object.prototype.hasOwnProperty.call(changes, id) ? changes[id] : f.value;
		var read; // restituisce il valore scelto
		var body = h('div');

		// Tutti i testi dei moduli ammettono grassetto, corsivo e sottolineato. Restano testo semplice solo quelli che non possono averli
		// (titolo e nome di pagine e categorie, occhielli salvati come campo, indirizzo della mappa, testi dentro un'icona o tra virgolette).
		var formattable = f.t === 'text' && (f.src === 'field' || f.src === 'metapost') && !f.map && !f.tn;

		if (f.t === 'text' && !formattable) {
			var ctl = f.multiline ? h('textarea') : h('input', { type: 'text' });
			ctl.value = start;
			body.appendChild(ctl);
			read = function () { return ctl.value; };
			setTimeout(function () { ctl.focus(); ctl.select && ctl.select(); }, 30);
			if (!f.multiline) {
				ctl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
			}
		} else if (f.t === 'rich' || formattable) {
			var tb = textToolbar(f.t === 'rich');
			var ed = h('div', { class: 'rich', contenteditable: 'true' });
			setEditorContent(ed, start);
			ed.addEventListener('paste', function (e) {
				e.preventDefault();
				var txt = (e.clipboardData || window.clipboardData).getData('text/plain');
				document.execCommand('insertText', false, f.multiline ? txt : txt.replace(/\r?\n+/g, ' '));
			});
			if (!f.multiline) {
				ed.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
			}
			body.appendChild(tb);
			body.appendChild(ed);
			read = function () { return cleanEditorHtml(ed, !!f.multiline); };
			setTimeout(function () {
				ed.focus();
				var r = document.createRange(); r.selectNodeContents(ed);
				var sl = window.getSelection(); sl.removeAllRanges(); sl.addRange(r);
			}, 30);
		} else if (f.t === 'number') {
			var num = h('input', { type: 'number', step: 'any', inputmode: 'decimal' });
			if (f.min !== undefined && f.min !== '') { num.setAttribute('min', f.min); }
			if (f.max !== undefined && f.max !== '') { num.setAttribute('max', f.max); }
			num.value = start;
			body.appendChild(num);
			read = function () { return num.value; };
			num.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doApply(); } });
			setTimeout(function () { num.focus(); num.select && num.select(); }, 30);
		} else if (f.t === 'select') {
			var chosen = [].concat(start === undefined || start === null ? [] : start).map(String);
			var ch = f.choices || {};
			if (f.multiple) {
				var boxes = [];
				Object.keys(ch).forEach(function (k) {
					var cb = h('input', { type: 'checkbox' }); cb.value = k; cb.checked = chosen.indexOf(k) !== -1;
					boxes.push(cb);
					body.appendChild(h('label', { class: 'chk' }, [cb, document.createTextNode(ch[k])]));
				});
				read = function () { return boxes.filter(function (b) { return b.checked; }).map(function (b) { return b.value; }); };
			} else {
				var sl1 = h('select');
				if (f.nullable) { sl1.appendChild(h('option', { value: '', text: '—' })); }
				Object.keys(ch).forEach(function (k) { var o = h('option', { value: k, text: ch[k] }); if (chosen[0] === k) { o.selected = true; } sl1.appendChild(o); });
				body.appendChild(sl1);
				read = function () { return sl1.value; };
				setTimeout(function () { sl1.focus(); }, 30);
			}
		} else if (f.t === 'link') {
			var v = start || {};
			var t = h('input', { type: 'text' }); t.value = v.title || '';
			var u = h('input', { type: 'text' }); u.value = v.url || '';
			var c = h('input', { type: 'checkbox' }); c.checked = v.target === '_blank';
			// Se il testo del link non è visibile nell'elemento (es. scheda cliccabile con solo una freccia) si chiede solo l'indirizzo.
			f.notext = !!(v.title && (el.textContent || '').toLowerCase().indexOf(String(v.title).toLowerCase()) === -1);
			if (!f.notext) {
				body.appendChild(h('label', { text: T.linkText }));
				body.appendChild(t);
			}
			body.appendChild(h('label', { text: T.linkUrl }));
			body.appendChild(u);
			body.appendChild(h('label', { class: 'chk' }, [c, document.createTextNode(T.newTab)]));
			read = function () { return { title: t.value, url: u.value, target: c.checked ? '_blank' : '' }; };
			setTimeout(function () { var first = f.notext ? u : t; first.focus(); first.select(); }, 30);
		} else if (f.t === 'shortcode') {
			var sc = h('input', { type: 'text' });
			sc.value = start || '';
			body.appendChild(h('label', { text: T.shortcodeLabel }));
			body.appendChild(sc);
			var forms = (cfg.shortcodes && cfg.shortcodes.forms) || [];
			if (forms.length) {
				var sel = h('select', {}, [h('option', { value: '', text: T.chooseForm })].concat(forms.map(function (fm) { return h('option', { value: fm.code, text: fm.title }); })));
				sel.style.cssText = 'width:100%;margin-top:8px;padding:8px;border:1.5px solid #cfc8e6;border-radius:9px;font-size:14px;background:#fff';
				sel.addEventListener('change', function () { if (sel.value) { sc.value = sel.value; } });
				body.appendChild(sel);
			}
			body.appendChild(h('p', { class: 'hint', text: T.shortcodeHint }));
			read = function () { return sc.value.trim(); };
			setTimeout(function () { sc.focus(); }, 30);
		} else if (f.t === 'list') {
			// Elenco: si scrive come in un documento (Invio = nuova voce, Backspace su una voce vuota la toglie). Una voce per riga.
			var lt = f.lines ? null : textToolbar(true); // elenco in un campo di testo: solo testo, senza grassetto
			var ul = h('ul', { class: 'rich listed', contenteditable: 'true' });
			(start || ['']).forEach(function (item) { var li = document.createElement('li'); if (f.lines) { li.textContent = item; } else { setEditorContent(li, item); } ul.appendChild(li); });
			if (!ul.children.length) { ul.appendChild(document.createElement('li')); }
			ul.addEventListener('paste', function (e) {
				e.preventDefault();
				var txt = (e.clipboardData || window.clipboardData).getData('text/plain');
				document.execCommand('insertText', false, txt.replace(/\r?\n+/g, ' '));
			});
			if (lt) { body.appendChild(lt); }
			body.appendChild(ul);
			body.appendChild(h('p', { class: 'hint', text: T.listHint }));
			read = function () {
				var out = [];
				ul.querySelectorAll(':scope > li').forEach(function (li) {
					var html = f.lines ? esc(li.textContent.trim()) : cleanEditorHtml(li, false).trim();
					if (html.replace(/<[^>]*>/g, '').trim()) { out.push(html); }
				});
				if (!out.length) { showToast(T.listEmpty, true); return undefined; }
				return out;
			};
			setTimeout(function () { ul.focus(); }, 30);
		} else if (f.t === 'icon') {
			var picked = start || '';
			var filter = 'all', iresults = [], ishown = 0;
			var cur = h('div', { class: 'icur' });
			var isearch = h('input', { type: 'text', placeholder: T.iconTitle });
			var itabs = h('div', { class: 'itabs' });
			var igrid = h('div', { class: 'ig' });
			var icount = h('p', { class: 'hint' });

			var paintCur = function () {
				cur.innerHTML = '';
				if (picked && iconLib) { var b = h('span', { class: 'icb' }); b.innerHTML = iconSvg(picked, 26); cur.appendChild(b); cur.appendChild(h('span', { text: picked.replace('brand-', '') })); }
				else { cur.appendChild(h('span', { class: 'none', text: T.iconNone })); }
			};
			var moreIcons = function () {
				var end = Math.min(iresults.length, ishown + 140), html = '';
				for (var i = ishown; i < end; i++) {
					var n = iresults[i];
					html += '<button type="button" class="ii' + (n === picked ? ' on' : '') + '" data-n="' + n + '" title="' + n + '">' + iconSvg(n, 22) + '</button>';
				}
				igrid.insertAdjacentHTML('beforeend', html);
				ishown = end;
			};
			var refreshIcons = function () {
				var terms = isearch.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
				iresults = iconLib.names.filter(function (n) {
					var brand = n.indexOf('brand-') === 0, mine = n.indexOf('svg-') === 0;
					if ((filter === 'brands' && !brand) || (filter === 'lines' && (brand || mine)) || (filter === 'mine' && !mine)) { return false; }
					var hay = (iconLib.keywords[n] || n) + ' ' + n;
					return terms.every(function (t) { return hay.indexOf(t) !== -1; });
				});
				if (terms.length) {
					var q = terms.join('-');
					var rank = function (n) { return n === q || n === 'brand-' + q ? 0 : (n.indexOf(q) === 0 || n.indexOf('brand-' + q) === 0 ? 1 : (n.indexOf(terms[0]) !== -1 ? 2 : 3)); };
					iresults = iresults.map(function (n, i) { return { n: n, r: rank(n), i: i }; }).sort(function (a, b) { return a.r - b.r || a.i - b.i; }).map(function (o) { return o.n; });
				}
				if (filter === 'mine' && iconLib.custom) { iresults.sort(function (a, b) { return iconLib.custom.indexOf(a) - iconLib.custom.indexOf(b); }); }
				igrid.innerHTML = ''; ishown = 0; igrid.scrollTop = 0;
				if (!iresults.length) { igrid.appendChild(h('p', { class: 'hint', text: T.iconEmpty })); }
				moreIcons();
				icount.textContent = iresults.length + ' ' + T.iconCount;
				placePopup();
			};

			var tabDefs = [['all', T.iconAll], ['lines', T.iconLines], ['brands', T.iconBrands]];
			if (cfg.canSvg) { tabDefs.push(['mine', T.iconMine]); }
			tabDefs.forEach(function (t) {
				var b = h('button', { type: 'button', class: t[0] === filter ? 'on' : '', text: t[1], onclick: function () {
					filter = t[0];
					itabs.querySelectorAll('button').forEach(function (x) { x.className = x === b ? 'on' : ''; });
					if (iconLib) { refreshIcons(); }
				} });
				itabs.appendChild(b);
			});

			igrid.addEventListener('scroll', function () { if (igrid.scrollTop + igrid.clientHeight >= igrid.scrollHeight - 120) { moreIcons(); } });
			igrid.addEventListener('click', function (e) {
				var b = e.target.closest ? e.target.closest('.ii') : null;
				if (!b) { return; }
				picked = b.getAttribute('data-n');
				igrid.querySelectorAll('.ii.on').forEach(function (x) { x.className = 'ii'; });
				b.className = 'ii on';
				paintCur();
			});
			isearch.addEventListener('input', function () { if (iconLib) { refreshIcons(); } });

			// "Carica SVG": Libreria media filtrata sugli SVG; quello scelto diventa un'icona "svg-ID".
			var uploadSvg = function () {
				if (!window.wp || !wp.media) { showToast(T.noMedia, true); return; }
				var fr = wp.media({ title: T.iconUploadTitle, button: { text: T.iconUseSvg }, multiple: false, library: { type: 'image/svg+xml' } });
				mediaOpen = true;
				host.style.display = 'none';
				fr.on('select', function () {
					var a = fr.state().get('selection').first().toJSON();
					fetch(cfg.iconsSvg + a.id, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } })
						.then(function (r) { return r.json(); })
						.then(function (d) {
							if (!d || !d.name) { showToast((d && d.message) || 'SVG non valido', true); return; }
							iconLib.icons[d.name] = d.inner;
							iconLib.svgs = iconLib.svgs || {};
							iconLib.svgs[d.name] = { vb: d.vb, paint: d.paint };
							iconLib.keywords[d.name] = d.keywords;
							if (iconLib.names.indexOf(d.name) === -1) { iconLib.names.unshift(d.name); }
							(iconLib.custom = iconLib.custom || []).unshift(d.name);
							picked = d.name;
							paintCur();
							filter = 'mine';
							itabs.querySelectorAll('button').forEach(function (x) { x.className = x.textContent === T.iconMine ? 'on' : ''; });
							refreshIcons();
						});
				});
				fr.on('open', function () { fr.content.mode('browse'); });
				fr.on('close', function () { mediaOpen = false; host.style.display = ''; });
				fr.open();
			};

			body.appendChild(cur);
			body.appendChild(isearch);
			body.appendChild(itabs);
			body.appendChild(igrid);
			body.appendChild(icount);
			// "Carica SVG" sotto le icone: tra il numero di icone e i pulsanti Annulla / Applica, con spazio sopra e sotto.
			if (cfg.canSvg) { body.appendChild(h('button', { type: 'button', class: 'btn full upl', text: '↑ ' + T.iconUpload, onclick: uploadSvg })); }
			if (f.allowNull) {
				body.appendChild(h('button', { type: 'button', class: 'lnk', text: T.iconNone, onclick: function () { picked = ''; igrid.querySelectorAll('.ii.on').forEach(function (x) { x.className = 'ii'; }); paintCur(); } }));
			}

			igrid.appendChild(h('p', { class: 'hint', text: T.iconLoading }));
			loadIcons(function () { paintCur(); refreshIcons(); placePopup(); });
			setTimeout(function () { isearch.focus(); }, 30);
			read = function () { return picked; };
		} else if (f.t === 'gallery' && !asSingle) {
			var list = (start || []).slice();
			var thumbs = f.thumbs || {};
			// Url delle foto per l'anteprima: quelle già in pagina (dall'originale) + quelle scelte ora.
			var urls = Object.assign({}, galState[id] ? galState[id].urls : {});
			galleryEls(id).forEach(function (n) {
				var gi = parseInt(n.getAttribute('data-live-i'), 10), gid = (f.value || [])[gi];
				if (gid !== undefined && !urls[gid]) { urls[gid] = elUrl(n); }
			});
			galUrls = urls;
			var touched = false;
			cancelFn = function () {
				if (!touched) { return; }
				if (galState[id]) { previewGallery(id, galState[id].list, galState[id].urls); } else { restoreGallery(id); }
			};
			var grid = h('div', { class: 'gal' });
			var addBtn;

			var move = function (i, d) {
				var j = i + d;
				if (j < 0 || j >= list.length) { return; }
				var tmp = list[i]; list[i] = list[j]; list[j] = tmp;
				paintGal(true);
			};

			var paintGal = function (changed) {
				if (changed) { touched = true; previewGallery(id, list, urls); }
				grid.innerHTML = '';
				list.forEach(function (gid, i) {
					var cell = h('div', { class: 'gcell' }, [
						h('img', { src: thumbs[gid] || '', alt: '' }),
						h('button', { type: 'button', class: 'gx', title: T.remove, text: '×', onclick: function () { list.splice(i, 1); paintGal(true); } }),
						h('div', { class: 'gm' }, [
							h('button', { type: 'button', text: '◀', title: T.moveLeft, onclick: function () { move(i, -1); } }),
							h('button', { type: 'button', text: '▶', title: T.moveRight, onclick: function () { move(i, 1); } })
						])
					]);
					grid.appendChild(cell);
				});
				var full = f.max && list.length >= f.max;
				if (addBtn) {
					addBtn.textContent = full ? T.galleryFull.replace('%d', f.max) : '+ ' + T.addImages;
					if (full) { addBtn.setAttribute('disabled', ''); } else { addBtn.removeAttribute('disabled'); }
				}
				placePopup();
			};

			addBtn = h('button', { type: 'button', class: 'btn full', onclick: function () {
				if (!cfg.canMedia || !(window.wp && wp.media)) { showToast(T.noMedia, true); return; }
				// Posti ancora liberi: se ne resta uno solo si sceglie una foto sola; altrimenti al massimo quante ne mancano.
				var remaining = f.max ? f.max - list.length : 0;
				var frame = wp.media({ title: T.chooseTitle, button: { text: T.chooseBtn }, multiple: remaining === 1 ? false : 'add', library: { type: 'image' } });
				mediaOpen = true;
				host.style.display = 'none';
				frame.on('open', function () {
					frame.content.mode('browse');
					// Non si possono selezionare più foto dei posti liberi: l'eccedenza viene tolta subito.
					if (remaining > 1) {
						var selection = frame.state().get('selection');
						selection.on('add', function (model) {
							if (selection.length > remaining) { selection.remove(model); }
						});
					}
				});
				frame.on('select', function () {
					var added = 0;
					frame.state().get('selection').each(function (m) {
						var a = m.toJSON();
						if (list.indexOf(a.id) !== -1 || (f.max && list.length >= f.max)) { return; }
						list.push(a.id);
						added++;
						thumbs[a.id] = (a.sizes && a.sizes.thumbnail) ? a.sizes.thumbnail.url : a.url;
						urls[a.id] = (a.sizes && (a.sizes.large || a.sizes.full)) ? (a.sizes.large || a.sizes.full).url : a.url;
					});
					paintGal(true);
					if (f.max && frame.state().get('selection').length > added && added) { showToast(T.galleryTrimmed.replace('%d', f.max), true); }
				});
				frame.on('close', function () { mediaOpen = false; host.style.display = ''; });
				frame.open();
			} });

			body.appendChild(grid);
			body.appendChild(addBtn);
			if (f.max) { body.appendChild(h('p', { class: 'hint', text: T.galleryMax.replace('%d', f.max) })); }
			if (f.hint) { body.appendChild(h('p', { class: 'hint', text: f.hint })); }
			paintGal();
			read = function () {
				if (f.min && list.length < f.min) { showToast(T.galleryMin.replace('%d', f.min), true); return undefined; }
				return list.slice();
			};
		} else { // image | file
			var picked = null; // { id, url, name }
			var previewed = false;
			cancelFn = function () {
				if (!previewed) { return; }
				if (committedUrl[id]) { applyMedia(el, { url: committedUrl[id] }); } else { restore(el, id); }
			};
			var area = h('div');
			var shown = el.tagName === 'IMG' ? (el.currentSrc || el.src) : null;
			var isVideo = f.media === 'video' || el.tagName === 'VIDEO';

			function paint(src, name) {
				area.innerHTML = '';
				if (src && !isVideo) { area.appendChild(h('img', { class: 'thumb', src: src })); }
				else if (name || isVideo) { area.appendChild(h('div', { class: 'file', text: name || (el.currentSrc || el.src || '').split('/').pop() })); }
			}
			paint(shown);
			body.appendChild(area);

			var rep = h('button', { type: 'button', class: 'btn full', text: (f.t === 'image' || asSingle) ? T.replaceImg : T.replaceFile, onclick: function () {
				if (!cfg.canMedia || !(window.wp && wp.media)) { showToast(T.noMedia, true); return; }
				var opts = { title: T.chooseTitle, button: { text: T.chooseBtn }, multiple: false };
				if (f.media) { opts.library = { type: f.media }; }
				var frame = wp.media(opts);
				mediaOpen = true;
				host.style.display = 'none';
				var done = function () { mediaOpen = false; host.style.display = ''; };
				frame.on('select', function () {
					var a = frame.state().get('selection').first().toJSON();
					var url = (a.sizes && (a.sizes.large || a.sizes.full)) ? (a.sizes.large || a.sizes.full).url : a.url;
					picked = { id: a.id, url: url, name: a.filename, alt: a.alt || '' };
					if (altInput) { altInput.value = a.alt || ''; } // il testo alternativo della nuova immagine nella Libreria media
					paint(url, a.filename);
					// Anteprima subito sulla pagina (si conferma con "Applica", si annulla con "Annulla").
					remember(el, id);
					applyMedia(el, { url: url });
					previewed = true;
					placePopup();
				});
				frame.on('open', function () { frame.content.mode('browse'); });
				frame.on('close', done);
				frame.open();
			} });
			body.appendChild(rep);

			// Immagini dei blocchi: il testo alternativo è di questa immagine in questa pagina. Cambiando immagine si propone quello della nuova (modificabile).
			var altInput = null;
			if (f.altEdit) {
				body.appendChild(h('label', { text: T.altLabel }));
				altInput = h('input', { type: 'text' });
				altInput.value = f.alt || '';
				body.appendChild(altInput);
			}

			read = function () {
				var altNow = altInput ? altInput.value : undefined;
				if (picked) { return { id: picked.id, url: picked.url, alt: altNow }; }
				if (altInput && altNow !== (f.alt || '') && f.value) { return { id: f.value, url: '', alt: altNow }; } // cambia solo il testo alternativo
				return null;
			};

			// Copertina del video: immagine mostrata finché il video carica; si cambia insieme al video.
			var pf = f.poster ? fields[f.poster] : null;
			var posterPreviewed = false;
			var cancelMain = cancelFn;
			cancelFn = function () {
				if (cancelMain) { cancelMain(); }
				if (!posterPreviewed) { return; }
				var o = originals[id];
				if (committedPoster[f.poster]) { el.setAttribute('poster', committedPoster[f.poster]); }
				else if (o && o.poster !== null) { el.setAttribute('poster', o.poster); } else { el.removeAttribute('poster'); }
			};
			if (pf) {
				var pArea = h('div');
				var pThumb = function (src) {
					pArea.innerHTML = '';
					if (src) { pArea.appendChild(h('img', { class: 'thumb small', src: src })); }
				};
				pThumb(el.getAttribute('poster') || pf.thumb);
				body.appendChild(h('label', { text: T.poster }));
				body.appendChild(pArea);
				body.appendChild(h('button', { type: 'button', class: 'btn full', text: T.replacePoster, onclick: function () {
					if (!cfg.canMedia || !(window.wp && wp.media)) { showToast(T.noMedia, true); return; }
					var fr = wp.media({ title: T.chooseTitle, button: { text: T.chooseBtn }, multiple: false, library: { type: 'image' } });
					mediaOpen = true;
					host.style.display = 'none';
					fr.on('open', function () { fr.content.mode('browse'); });
					fr.on('select', function () {
						var a = fr.state().get('selection').first().toJSON();
						var url = (a.sizes && (a.sizes.medium || a.sizes.full)) ? (a.sizes.medium || a.sizes.full).url : a.url;
						pickedPoster = { id: a.id, url: url };
						pThumb(url);
						remember(el, id);
						el.setAttribute('poster', url);
						posterPreviewed = true;
						placePopup();
					});
					fr.on('close', function () { mediaOpen = false; host.style.display = ''; });
					fr.open();
				} }));
			}
		}

		function doApply() {
			var val = read();
			if (val === undefined) { return; }
			if (f.t === 'gallery' && !asSingle) {
				if (sameValue(val, f.value)) { clearChange(id, el); } else { changes[id] = val; galState[id] = { list: val.slice(), urls: Object.assign({}, galUrls) }; markGallery(id, true); renderBar(); }
				closePopup(true);
				return;
			}
			if (f.t === 'icon' && val === f.value) { if (Object.prototype.hasOwnProperty.call(changes, id)) { clearChange(id, el); } closePopup(true); return; }
			if (val === null && !pickedPoster) { closePopup(true); return; } // media non cambiato
			if (pickedPoster && f.poster) {
				remember(el, id);
				changes[f.poster] = pickedPoster.id;
				committedPoster[f.poster] = pickedPoster.url;
				el.setAttribute('poster', pickedPoster.url);
				el.setAttribute('data-live-dirty', '');
				if (val === null) { renderBar(); closePopup(true); return; }
			}
			if (f.t === 'link' && !String(val.url).trim() && !String(val.title).trim()) { return; }
			if (f.t !== 'image' && f.t !== 'file' && sameValue(val, f.value) && !Object.prototype.hasOwnProperty.call(changes, id)) { closePopup(true); return; }
			// image/file: lo stato salvato è l'id, l'anteprima usa l'url
			if (f.t === 'image' || f.t === 'file' || asSingle) {
				remember(el, id);
				changes[id] = f.altEdit ? { id: val.id, alt: val.alt } : (asSingle ? [val.id] : val.id);
				committedUrl[id] = val.url;
				applyMedia(el, val);
				if (f.altEdit && typeof val.alt === 'string' && el.tagName === 'IMG') { el.setAttribute('alt', val.alt); }
				el.setAttribute('data-live-dirty', '');
				renderBar();
			} else {
				setChange(id, el, val);
			}
			closePopup(true);
		}

		var hasChange = Object.prototype.hasOwnProperty.call(changes, id) || (f.poster && Object.prototype.hasOwnProperty.call(changes, f.poster));
		var revert = hasChange
			? h('button', { type: 'button', class: 'lnk', text: T.revert, onclick: function () { if (f.poster) { delete changes[f.poster]; } clearChange(id, el); closePopup(true); } })
			: null;

		pop.innerHTML = '';
		pop.appendChild(h('h4', { text: f.group ? f.group + ' · ' + f.label : f.label }));
		pop.appendChild(body);
		pop.appendChild(h('div', { class: 'row' }, [
			revert,
			h('span', { class: 'sp' }),
			h('button', { type: 'button', class: 'btn', text: T.cancel, onclick: function () { closePopup(); } }),
			h('button', { type: 'button', class: 'btn go', text: T.apply, onclick: doApply })
		]));
		pop.appendChild(h('p', { class: 'hint', text: T.hint }));
		pop.addEventListener('keydown', function (e) {
			if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') { e.preventDefault(); doApply(); }
		});

		current = { id: id, el: el, cancel: function () { if (cancelFn) { cancelFn(); } } };
		pop.style.width = f.t === 'icon' ? '440px' : '';
		pop.style.display = 'block';
		lockScroll(true);
		placeHl(el);
		placePopup();
	}

	/* ---------------------------------------------------------------- avvio */

	function applyMobile() {
		host.style.display = MOBILE.matches ? 'none' : '';

		if (MOBILE.matches && on && !saving) { toggle(); } // se la finestra si stringe mentre si modifica, si esce dalla modalità modifica
	}

	function start() {
		document.body.appendChild(host);
		renderBar();
		applyMobile();
		if (MOBILE.addEventListener) { MOBILE.addEventListener('change', applyMobile); }
		try {
			if (sessionStorage.getItem('starterLiveSaved')) {
				sessionStorage.removeItem('starterLiveSaved');
				showToast('✓ ' + T.saved, false, 3500);
			}
		} catch (e) { /* niente */ }
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
