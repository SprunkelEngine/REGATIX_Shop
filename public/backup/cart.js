(function(){
  const KEY = 'pv_cart_v1';

  const elList = document.getElementById('pv_cart_list');
  const elCount = document.getElementById('pv_cart_count');
  const elSumGross = document.getElementById('pv_cart_sum_gross');
  const elSumNet = document.getElementById('pv_cart_sum_net');
  const elHint = document.getElementById('pv_cart_hint');

  const btnClear = document.getElementById('pv_cart_clear');
  const btnProforma = document.getElementById('pv_proforma_btn');

  let articleToImg = {};

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

  function loadCart(){
	try{
	  const raw = localStorage.getItem(KEY);
	  const j = raw ? JSON.parse(raw) : null;
	  return Array.isArray(j) ? j : [];
	}catch(e){
	  return [];
	}
  }

  function saveCart(items){
	localStorage.setItem(KEY, JSON.stringify(items));
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
	if(it && it.image) return String(it.image);
	const art = String(it.article || '');
	return articleToImg[art] || '';
  }

  function render(){
	if(!elList || !elCount || !elSumGross || !elSumNet){
	  return;
	}

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

	// qty change
	elList.querySelectorAll('.qty').forEach(inp => {
	  inp.addEventListener('input', () => {
		const idx = Number(inp.getAttribute('data-idx'));
		const items = loadCart();
		if(!items[idx]) return;

		const q = Math.max(1, parseInt(String(inp.value||'1'),10) || 1);
		items[idx].quantity = q;

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

  async function loadBootstrapMap(){
	try{
	  const res = await fetch('../api.php?mode=bootstrap');
	  const json = await res.json();

	  const map = {};
	  const articles = (json && json.content && json.content.articles) ? json.content.articles : {};
	  for(const [art, obj] of Object.entries(articles)){
		const imgs = Array.isArray(obj && obj.images) ? obj.images : [];
		if(imgs.length) map[String(art)] = String(imgs[0]);
	  }
	  articleToImg = map;
	}catch(e){
	  articleToImg = {};
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
	  const res = await fetch('proforma_cart.php', {
		method: 'POST',
		headers: {'Content-Type': 'application/json'},
		body: JSON.stringify({ items })
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

  // Buttons crash-sicher binden
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

  loadBootstrapMap().finally(render);
})();
