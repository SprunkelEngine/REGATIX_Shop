(function(){
  "use strict";

  const KEY = 'pv_cart_v1';

  // ✅ NEU: persistenter Cache für Artikel->Bild (über Produktarten hinweg)
  const IMG_CACHE_KEY = 'pv_article_img_map_v1';

  // ✅ NEU: persistenter Cache für Artikel->Produktart/Produktkey (über Produktarten hinweg)
  const PROD_CACHE_KEY = 'pv_article_productkey_map_v1';

  const elList = document.getElementById('pv_cart_list');
  const elCount = document.getElementById('pv_cart_count');
  const elSumGross = document.getElementById('pv_cart_sum_gross');
  const elSumNet = document.getElementById('pv_cart_sum_net');
  const elHint = document.getElementById('pv_cart_hint');

  const btnClear = document.getElementById('pv_cart_clear');
  const btnProforma = document.getElementById('pv_proforma_btn');

  // In-Memory maps (werden aus persistentem Cache geladen/gefüllt)
  let articleToImg = {};
  let articleToProductKey = {};

  function toFloatDE(v){
	if(v === null || v === undefined || v === '') return null;
	if(typeof v === 'number' && Number.isFinite(v)) return v;

	let s = String(v).trim();
	if(s === '') return null;

	s = s.replace(/[^\d,.\-]/g, '');

	if(s.includes('.') && s.includes(',')){
	  s = s.replace(/\./g, '').replace(',', '.');
	} else {
	  s = s.replace(',', '.');
	}

	const n = Number(s);
	return Number.isFinite(n) ? n : null;
  }

  function money(n){
	const v = toFloatDE(n);
	if(v === null) return '—';
	return v.toLocaleString('de-DE', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
  }

  function escapeHtml(s){
	return String(s ?? '')
	  .replaceAll('&','&amp;')
	  .replaceAll('<','&lt;')
	  .replaceAll('>','&gt;')
	  .replaceAll('"','&quot;')
	  .replaceAll("'",'&#39;');
  }

  // --- LocalStorage Helpers (robust) ---
  function safeJsonParse(raw, fallback){
	try{
	  const j = raw ? JSON.parse(raw) : null;
	  return (j === null || j === undefined) ? fallback : j;
	}catch(e){
	  return fallback;
	}
  }

  function deepClone(obj){
	// structuredClone wenn verfügbar, fallback JSON
	try{
	  if(typeof structuredClone === 'function') return structuredClone(obj);
	}catch(e){}
	return JSON.parse(JSON.stringify(obj));
  }

  // ✅ Item normalisieren: nur sinnvolle Felder, stabile Defaults
  // Wichtig: wir "halten fest" (persistieren) image + product_key, sobald bekannt
  function normalizeItem(it){
	const o = (it && typeof it === 'object') ? it : {};

	const article = String(o.article || '');
	const offer   = String(o.offer || '');

	const title = o.title ?? o.name ?? o.article ?? 'Artikel';

	// image: erst aus Item, sonst aus Cache
	const img = (o.image && String(o.image).trim())
	  ? String(o.image).trim()
	  : (articleToImg[article] || '');

	// product key / group: erst aus Item, sonst aus Cache (wenn wir den Artikel schon kennen)
	const pk = (o.product_key && String(o.product_key).trim())
	  ? String(o.product_key).trim()
	  : (articleToProductKey[article] || '');

	// Auswahltext stabilisieren
	const selectionText = (o.selection_text && String(o.selection_text).trim())
	  ? String(o.selection_text).trim()
	  : '';

	// Preise unverändert übernehmen (nur als Werte speichern)
	const priceGrossUnit = o.price_gross_unit;
	const priceNetUnit   = o.price_net_unit;

	// quantity: min 1
	const quantity = Math.max(1, parseInt(String(o.quantity || 1), 10) || 1);

	// Alles, was wir NICHT verändern wollen, bleibt wie im Objekt,
	// aber wir schreiben die Schlüssel zuverlässig zurück.
	return {
	  ...o,
	  title: title,
	  article: article,
	  offer: offer,
	  selection_text: selectionText,
	  image: img,
	  product_key: pk,
	  product_group: o.product_group ? String(o.product_group) : (pk || ''),
	  price_gross_unit: priceGrossUnit,
	  price_net_unit: priceNetUnit,
	  quantity: quantity,
	  // Lock-Flag (kann von anderen Scripts respektiert werden)
	  __locked: true
	};
  }

  function loadCart(){
	const raw = localStorage.getItem(KEY);
	const j = safeJsonParse(raw, []);
	const items = Array.isArray(j) ? j : [];
	// ✅ immer normalisieren (stabilisiert Daten)
	return items.map(normalizeItem);
  }

  // ✅ Speichern: immer Normalisierung + Deepclone (keine Referenzen)
  function saveCart(items){
	const arr = Array.isArray(items) ? items : [];
	const normalized = arr.map(normalizeItem);
	localStorage.setItem(KEY, JSON.stringify(deepClone(normalized)));
  }

  function lineGross(item){
	const unit = toFloatDE(item.price_gross_unit);
	const q = Math.max(1, Number(item.quantity || 1));
	return unit === null ? null : unit * q;
  }

  function lineNet(item){
	const unit = toFloatDE(item.price_net_unit);
	const q = Math.max(1, Number(item.quantity || 1));
	return unit === null ? null : unit * q;
  }

  function total(items, fn){
	let sum = 0;
	let any = false;
	for(const it of items){
	  const v = fn(it);
	  if(v !== null){
		sum += v;
		any = true;
	  }
	}
	return any ? sum : null;
  }

  function imgForItem(it){
	// ✅ FIX: niemals nur "aktuelles Produkt" bootstrap verwenden
	if(it && it.image) return String(it.image);
	const art = String(it.article || '');
	return articleToImg[art] || '';
  }

  function render(){
	if(!elList || !elCount || !elSumGross || !elSumNet){
	  return;
	}

	// ✅ immer normalisierte Items verwenden
	const items = loadCart();

	const totalQty = items.reduce((a,it)=>a + Math.max(1, Number(it.quantity||1)), 0);
	elCount.textContent = items.length ? `${items.length} Position(en) · ${totalQty} Stück` : 'Leer';

	if(!items.length){
	  elList.innerHTML = `<div class="small">Dein Warenkorb ist leer.</div>`;
	  elSumGross.textContent = '—';
	  elSumNet.textContent = '';
	  if(elHint) elHint.textContent = '';
	  return;
	}

	elList.innerHTML = items.map((it, idx) => {
	  const meta = (it.selection_text || '').trim();
	  const offerTxt = (it.offer || '').trim();

	  const gross = lineGross(it);
	  const net = lineNet(it);

	  const img = imgForItem(it);
	  const imgHtml = img
		? `<img src="../images/${escapeHtml(img)}" alt="">`
		: `<div class="small" style="padding:10px">Kein Bild</div>`;

	  return `
		<div class="cart-row">
		  <div class="cart-left">
			<div class="cart-img">${imgHtml}</div>

			<div class="cart-left-text">
			  <div class="cart-title">${escapeHtml(it.title || it.article || 'Artikel')}</div>
			  <div class="cart-meta">
				<div><b>Artikelnr.:</b> ${escapeHtml(it.article || '')}</div>
				${offerTxt ? `<div><b>Angebot:</b> ${escapeHtml(offerTxt)}</div>` : ``}
				${meta ? `<div>${escapeHtml(meta)}</div>` : ``}
			  </div>
			</div>
		  </div>

		  <div class="cart-right">
			<div><b>${money(gross)}</b></div>
			${net !== null ? `<div class="net">${money(net)} ohne MwSt.</div>` : ``}

			<div style="margin-top:10px; display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; align-items:center">
			  <input class="qty" type="number" min="1" step="1" value="${escapeHtml(String(it.quantity || 1))}" data-idx="${idx}">
			  <button class="btn pv_remove" type="button" data-idx="${idx}" style="height:38px">Entfernen</button>
			</div>
		  </div>
		</div>
	  `;
	}).join('');

	const sumG = total(items, lineGross);
	const sumN = total(items, lineNet);
	elSumGross.textContent = sumG !== null ? money(sumG) : '—';
	elSumNet.textContent = sumN !== null ? (money(sumN) + ' ohne MwSt.') : '';

	if(elHint){
	  if(sumN !== null && sumN > 1500){
		elHint.textContent = 'Hinweis: Netto > 1.500 € – Angebot kann günstiger sein.';
	  } else {
		elHint.textContent = '';
	  }
	}

	// ✅ qty change: nur quantity ändern, sonst nix
	elList.querySelectorAll('.qty').forEach(inp => {
	  inp.addEventListener('input', () => {
		const idx = Number(inp.getAttribute('data-idx'));
		const items = loadCart();
		if(!items[idx]) return;

		const q = Math.max(1, parseInt(String(inp.value||'1'),10) || 1);

		// nur quantity ändern
		const fixed = normalizeItem(items[idx]);
		fixed.quantity = q;
		items[idx] = fixed;

		saveCart(items);
		render();
	  });
	});

	// remove
	elList.querySelectorAll('.pv_remove').forEach(btn => {
	  btn.addEventListener('click', () => {
		const idx = Number(btn.getAttribute('data-idx'));
		const items = loadCart();
		items.splice(idx, 1);
		saveCart(items);
		render();
	  });
	});
  }

  // --- Persistent Cache for images/product keys ---
  function loadImgCache(){
	const raw = localStorage.getItem(IMG_CACHE_KEY);
	const j = safeJsonParse(raw, {});
	return (j && typeof j === 'object' && !Array.isArray(j)) ? j : {};
  }
  function saveImgCache(map){
	localStorage.setItem(IMG_CACHE_KEY, JSON.stringify(map || {}));
  }

  function loadProdCache(){
	const raw = localStorage.getItem(PROD_CACHE_KEY);
	const j = safeJsonParse(raw, {});
	return (j && typeof j === 'object' && !Array.isArray(j)) ? j : {};
  }
  function saveProdCache(map){
	localStorage.setItem(PROD_CACHE_KEY, JSON.stringify(map || {}));
  }

  // ✅ NEU: Bootstrap laden, aber Ergebnisse in persistenten Cache MERGEN
  // und vorhandene Cart-Items "stempeln", sofern Artikel in Bootstrap vorkommt.
  async function loadBootstrapMap(){
	// Cache laden
	articleToImg = loadImgCache();
	articleToProductKey = loadProdCache();

	try{
	  const res = await fetch('../api.php?mode=bootstrap', { cache: 'no-store' });
	  const json = await res.json().catch(()=>null);

	  // Produkt-Key aus Bootstrap (mehrere mögliche Felder)
	  const bootProductKey =
		String(
		  (json && (json.product_key || json.productKey)) ||
		  (json && json.boot && (json.boot.product_key || json.boot.productKey)) ||
		  (json && json.content && json.content.product && (json.content.product.key || json.content.product.product_key)) ||
		  ''
		).trim();

	  const map = {};
	  const articles = (json && json.content && json.content.articles) ? json.content.articles : {};

	  // Artikel->Bild aus Bootstrap
	  for(const [art, obj] of Object.entries(articles)){
		const imgs = Array.isArray(obj && obj.images) ? obj.images : [];
		if(imgs.length) map[String(art)] = String(imgs[0]);
	  }

	  // ✅ Merge in persistente Maps
	  const mergedImg = { ...articleToImg, ...map };
	  articleToImg = mergedImg;
	  saveImgCache(mergedImg);

	  // ✅ Wenn wir einen ProductKey haben: stempeln wir den für alle Artikel aus Bootstrap
	  if (bootProductKey) {
		const mergedProd = { ...articleToProductKey };
		for (const art of Object.keys(articles)) {
		  const a = String(art);
		  if (a) mergedProd[a] = bootProductKey;
		}
		articleToProductKey = mergedProd;
		saveProdCache(mergedProd);

		// ✅ Und jetzt: vorhandene Cart-Items, deren Artikel in Bootstrap vorkommt, nachziehen
		const items = loadCart();
		let changed = false;

		const artsSet = new Set(Object.keys(articles).map(a => String(a)));

		for (let i=0; i<items.length; i++){
		  const it = items[i];
		  const art = String(it.article || '');
		  if (!art || !artsSet.has(art)) continue;

		  const fixed = normalizeItem(it);

		  // Bild nachziehen
		  if (!fixed.image && mergedImg[art]) {
			fixed.image = mergedImg[art];
			changed = true;
		  }

		  // ProductKey nachziehen
		  if (!fixed.product_key && bootProductKey) {
			fixed.product_key = bootProductKey;
			fixed.product_group = bootProductKey;
			changed = true;
		  }

		  items[i] = fixed;
		}

		if (changed) saveCart(items);
	  }
	}catch(e){
	  // Fallback: nur Cache nutzen
	  articleToImg = loadImgCache();
	  articleToProductKey = loadProdCache();
	}
  }

  async function downloadProformaFromCart(){
	const items = loadCart();
	if(!items.length){
	  alert('Warenkorb ist leer.');
	  return;
	}

	btnProforma && (btnProforma.disabled = true);
	btnProforma && (btnProforma.textContent = 'ProForma wird erstellt…');

	try{
	  // ✅ extra: normalisierte Items senden (inkl. image/product_key)
	  const payload = { items: items.map(normalizeItem) };

	  const res = await fetch('proforma_cart.php', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify(payload)
	  });

	  if(!res.ok){
		const txt = await res.text().catch(()=> '');
		throw new Error(txt || ('HTTP ' + res.status));
	  }

	  const blob = await res.blob();
	  const cd = res.headers.get('Content-Disposition') || '';
	  let filename = 'proforma.pdf';
	  const m = cd.match(/filename="([^"]+)"/i);
	  if(m && m[1]) filename = m[1];

	  const url = URL.createObjectURL(blob);
	  const a = document.createElement('a');
	  a.href = url;
	  a.download = filename;
	  document.body.appendChild(a);
	  a.click();
	  a.remove();
	  setTimeout(()=> URL.revokeObjectURL(url), 1500);
	}catch(err){
	  console.error(err);
	  alert('ProForma Fehler: ' + (err?.message || err));
	}finally{
	  btnProforma && (btnProforma.disabled = false);
	  btnProforma && (btnProforma.textContent = 'ProForma (PDF)');
	}
  }

  if(btnClear){
	btnClear.addEventListener('click', () => {
	  if(!confirm('Warenkorb wirklich leeren?')) return;
	  saveCart([]);
	  render();
	});
  }

  if(btnProforma){
	btnProforma.addEventListener('click', downloadProformaFromCart);
  }

  // ✅ Reihenfolge: erst Cache/Bootstrap mergen, dann rendern
  loadBootstrapMap().finally(render);
})();