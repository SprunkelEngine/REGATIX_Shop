<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$boot = $repo->bootstrap();

$title = (string)($boot['content']['product']['title'] ?? 'Warenkorb');
?><!doctype html>
<html lang="de">
<head>
	
	
	<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','GTM-W5WRQLFN');</script>
<!-- End Google Tag Manager -->
	
	
	
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>REGATIX SHOP | Warenkorb</title>
  <link rel="stylesheet" href="assets/styles.css">

  <style>
	.pill-toggle{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border); background:rgba(0,0,0,.04);
	  color:var(--text); cursor:pointer; display:inline-flex; align-items:center; gap:8px;
	}
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }

	:root{
	  --bg:#ffffff; --card:#ffffff; --text:#000000; --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14); --accent:#95bf20; --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; }
	.card{ background: var(--card) !important; }
	.btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
	.btn:hover{ border-color: rgba(149,191,32,.55) !important; }

	body.layout-pro{
	  --bg:#0b0e14; --card:#121827; --text:#e8f0ff; --muted:#9ab0c7;
	  --border:rgba(255,255,255,.10); --accent:#76a7ff; --shadow:0 10px 30px rgba(0,0,0,.35);
	  background:
		radial-gradient(1200px 600px at 30% 0%, rgba(118,167,255,.18), transparent 55%),
		radial-gradient(1200px 600px at 70% 0%, rgba(118,255,214,.08), transparent 55%),
		var(--bg) !important;
	}
	body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
	body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
	body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }
	body.layout-pro .pill-toggle{ background: rgba(0,0,0,.18); color: var(--text); }
	body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

	.net{ color: rgba(0,0,0,.45) !important; font-size: 13px; }
	body.layout-pro .net{ color: rgba(232,240,255,.55) !important; }

	.cart-row{
	  display:flex; gap:12px; justify-content:space-between; align-items:flex-start;
	  padding:12px 0; border-top:1px solid var(--border);
	}
	.cart-row:first-child{ border-top:0; padding-top:0; }

	.cart-left{ flex:1; min-width: 240px; display:flex; gap:12px; align-items:flex-start; }
	.cart-img{
	  width:110px; height:80px; border-radius: 12px; border: 1px solid var(--border);
	  background: rgba(0,0,0,.03); overflow:hidden; flex:0 0 auto;
	  display:flex; align-items:center; justify-content:center;
	}
	.cart-img img{ width:100%; height:100%; object-fit: cover; display:block; }
	body.layout-pro .cart-img{ background: rgba(0,0,0,.25); }

	.cart-left-text{ flex:1; min-width:180px; }
	.cart-title{ font-weight:700; }
	.cart-meta{ margin-top:6px; color: var(--muted); font-size: 13px; }
	.cart-right{ min-width: 220px; text-align:right; }

	.qty{
	  width: 90px; height: 38px; border-radius: 12px; border: 1px solid var(--border);
	  padding: 0 10px; background: rgba(0,0,0,.03); color: var(--text);
	}
	body.layout-pro .qty{ background: rgba(0,0,0,.25); color: var(--text); }

	.tiny-note{ margin-top:8px; }
  </style>
</head>

<body>
	<!-- Google Tag Manager (noscript) -->
	<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-W5WRQLFN"
	height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
	<!-- End Google Tag Manager (noscript) -->

	
	

<!-- ✅ Add-to-cart via URL: ?product=...&article=...&qty=...&offer=... -->
<script>
(function(){
  const p = new URLSearchParams(location.search);
  const product = (p.get('product') || '').trim();
  const article = (p.get('article') || '').trim();
  if(!product || !article) return;

  const offer = (p.get('offer') || '').trim();

  // ✅ Preise direkt aus URL (vom Angebot)
  const priceGrossFromUrl = p.get('price_gross_unit');
  const priceNetFromUrl   = p.get('price_net_unit');

  let qty = parseInt((p.get('qty') || '1'), 10);
  if(!Number.isFinite(qty) || qty < 1) qty = 1;

  const KEY = 'pv_cart_v1';

  function loadCart(){
	try{
	  const raw = localStorage.getItem(KEY);
	  const j = raw ? JSON.parse(raw) : null;
	  return Array.isArray(j) ? j : [];
	}catch(e){ return []; }
  }
  function saveCart(items){
	localStorage.setItem(KEY, JSON.stringify(items));
  }

  function numOrNull(v){
	if(v === null || v === undefined || v === '') return null;
	if(typeof v === 'number' && Number.isFinite(v)) return v;
	let s = String(v).trim();
	if(!s) return null;
	s = s.replace(/[^\d,.\-]/g,'');
	if(s.includes('.') && s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
	else s = s.replace(',', '.');
	const n = Number(s);
	return Number.isFinite(n) ? n : null;
  }

  async function fetchItemDetails(){
	// Erwartet: ../api.php?mode=detail&product=...&article=...&offer=...
	const url = `../api.php?mode=detail&product=${encodeURIComponent(product)}&article=${encodeURIComponent(article)}${offer ? `&offer=${encodeURIComponent(offer)}` : ''}`;
	const res = await fetch(url, {credentials:'same-origin'}).catch(()=>null);
	if(!res || !res.ok) return null;
	const json = await res.json().catch(()=>null);
	return json || null;
  }

  function keyOf(it){
	// ✅ Angebote getrennt halten
	return String(it.article||'') + '|' + String(it.offer||'');
  }

  function upsertCart(item){
	const items = loadCart();
	const idx = items.findIndex(it => keyOf(it) === keyOf(item));

	if(idx >= 0){
	  const oldQty = Math.max(1, parseInt(String(items[idx].quantity || '1'),10) || 1);
	  items[idx].quantity = oldQty + item.quantity;

	  // bessere Daten übernehmen
	  if(item.title) items[idx].title = item.title;
	  if(item.selection_text) items[idx].selection_text = item.selection_text;
	  if(item.image) items[idx].image = item.image;
	  if(item.price_gross_unit !== null) items[idx].price_gross_unit = item.price_gross_unit;
	  if(item.price_net_unit !== null)   items[idx].price_net_unit   = item.price_net_unit;
	} else {
	  items.push(item);
	}
	saveCart(items);
  }

  async function run(){
	// Basis: reicht, falls API nix liefert
	const baseItem = {
	  product: product,
	  offer: offer,
	  article: article,
	  title: article,
	  selection_text: '',
	  quantity: qty,
	  // ✅ Erst URL-Preise übernehmen
	  price_gross_unit: numOrNull(priceGrossFromUrl),
	  price_net_unit:   numOrNull(priceNetFromUrl),
	  image: ''
	};

	// 1) Detail versuchen (inkl. offer)
	const detail = await fetchItemDetails();

	// 2) Daten robust extrahieren
	if(detail){
	  const artObj = (detail.article && typeof detail.article === 'object') ? detail.article : null;
	  const content = (detail.content_article && typeof detail.content_article === 'object') ? detail.content_article : null;

	  // Titel
	  const t =
		(content && (content.title || content.name)) ||
		(artObj && (artObj.title || artObj.name)) ||
		detail.title || detail.name || '';
	  if(t) baseItem.title = String(t);

	  // Auswahltext / Subtitle
	  const sel =
		(content && (content.selection_text || content.subtitle)) ||
		detail.selection_text || detail.subtitle || '';
	  if(sel) baseItem.selection_text = String(sel);

	  // Bild
	  const imgs = (content && Array.isArray(content.images)) ? content.images : [];
	  if(imgs.length) baseItem.image = String(imgs[0]);

	  // Preise aus API nur setzen, wenn URL keine geliefert hat
	  const pg =
		(artObj && (artObj.price_gross_unit ?? artObj.price_gross ?? artObj.gross ?? null)) ??
		(detail.price_gross_unit ?? detail.price_gross ?? null);
	  const pn =
		(artObj && (artObj.price_net_unit ?? artObj.price_net ?? artObj.net ?? null)) ??
		(detail.price_net_unit ?? detail.price_net ?? null);

	  if(baseItem.price_gross_unit === null){
		const pgN = numOrNull(pg);
		if(pgN !== null) baseItem.price_gross_unit = pgN;
	  }
	  if(baseItem.price_net_unit === null){
		const pnN = numOrNull(pn);
		if(pnN !== null) baseItem.price_net_unit = pnN;
	  }
	}

	upsertCart(baseItem);

	// URL bereinigen (Reload addet sonst erneut)
	history.replaceState({}, document.title, location.pathname);
  }

  run();
})();
</script>

<div id="logo">
  <div class="logostage">
	<div id="logologo">
	  <a href="https://regatix.com" title="Zurück zur Homepage">
		<img alt="" src="https://www.regatix.com/media/regatixshoplogo.png" />
	  </a>

	  <div id="regatixoben">
		<a href="https://regatix.com" title="Zurück zur Homepage">
		  <img alt="" src="https://www.regatix.com/media/REGATIX/SHOP_zeigt_nach_links.png" />
		</a>
	  </div>
	</div>
  </div>
</div>

<div class="container">
  <div class="header">
	<div>
	  <div class="h-title">Warenkorb</div>
	</div>
	<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
	  <button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
	  <a class="btn" href="index.php">Zurück</a>
	  <a class="btn" href="checkout.php">Zur Kasse</a>
	</div>
  </div>

  <div class="card">
	<div class="card-h">
	  <div class="card-title">Positionen</div>
	  <div class="small" id="pv_cart_count">—</div>
	</div>
	<div class="card-b">
	  <div id="pv_cart_list"></div>

	  <div class="hr"></div>

	  <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-start">
		<div>
		  <div class="small">Zwischensumme (brutto)</div>
		  <div class="price-gross" id="pv_cart_sum_gross">—</div>
		  <div class="net" id="pv_cart_sum_net"></div>
		  <div class="small tiny-note" id="pv_cart_hint"></div>
		</div>

		<div style="display:flex; gap:10px; flex-wrap:wrap">
		  <button class="btn" id="pv_cart_clear" type="button">Warenkorb leeren</button>
		  <a class="btn" href="checkout.php">Zur Kasse</a>
		</div>
	  </div>

	</div>
  </div>
</div>

<script>
  (function(){
	const KEY = 'pv_layout';
	const btn = document.getElementById('pv_layout_toggle');

	function setButtonLabel(){
	  const isPro = document.body.classList.contains('layout-pro');
	  btn.textContent = 'Layout: ' + (isPro ? 'Hell' : 'Dunkel');
	}
	function apply(mode){
	  document.body.classList.toggle('layout-pro', mode === 'pro');
	  setButtonLabel();
	}
	const saved = localStorage.getItem(KEY);
	apply(saved === 'pro' ? 'pro' : 'light');

	btn.addEventListener('click', () => {
	  const next = document.body.classList.contains('layout-pro') ? 'light' : 'pro';
	  localStorage.setItem(KEY, next);
	  apply(next);
	});
  })();
</script>

<script src="assets/cart.js"></script>

<div id="footer"><p>REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull; Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
	  Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull; Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr  <br><a href="https://www.regatix.com/pages/start/impressum.php" target="_blank">Impressum</a> | <a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank">Datenschutz</a> | <a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank">Versandbedingungen</a> | <a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Wideruf</a> | <a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank">SHOP Bedingungen</a></p>
</div>
</body>
</html>
