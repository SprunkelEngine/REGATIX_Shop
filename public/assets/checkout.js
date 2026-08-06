(function(){
  "use strict";

  const KEY = "pv_cart_v1";

  const elList     = document.getElementById("pv_sum_list");
  const elMeta     = document.getElementById("pv_sum_meta");
  const elGross    = document.getElementById("pv_sum_gross");
  const elNet      = document.getElementById("pv_sum_net");

  const hidBuy     = document.getElementById("pv_buy_order_json");
  const hidOffer   = document.getElementById("pv_offer_order_json");

  function safeJsonParse(raw, fallback){
	try { return raw ? JSON.parse(raw) : fallback; } catch(e){ return fallback; }
  }

  function deepClone(obj){
	try { if (typeof structuredClone === "function") return structuredClone(obj); } catch(e){}
	return JSON.parse(JSON.stringify(obj));
  }

  function toFloatDE(v){
	if(v === null || v === undefined || v === "") return null;
	if(typeof v === "number" && Number.isFinite(v)) return v;

	let s = String(v).trim();
	if(!s) return null;

	s = s.replace(/[^\d,.\-]/g, "");
	if(s.includes(".") && s.includes(",")) s = s.replace(/\./g, "").replace(",", ".");
	else s = s.replace(",", ".");

	const n = Number(s);
	return Number.isFinite(n) ? n : null;
  }

  function money(n){
	const v = toFloatDE(n);
	if(v === null) return "—";
	return v.toLocaleString("de-DE", {minimumFractionDigits:2, maximumFractionDigits:2}) + " €";
  }

  function escapeHtml(s){
	return String(s ?? "")
	  .replaceAll("&","&amp;")
	  .replaceAll("<","&lt;")
	  .replaceAll(">","&gt;")
	  .replaceAll('"',"&quot;")
	  .replaceAll("'","&#39;");
  }

  function loadCart(){
	const raw = localStorage.getItem(KEY);
	const arr = safeJsonParse(raw, []);
	return Array.isArray(arr) ? arr : [];
  }

  // ✅ extrem wichtig: Item stabilisieren, ohne andere Positionen zu beeinflussen
  // -> KEIN globales "product_key" auf alle anwenden
  function normalizeItem(it){
	const o = (it && typeof it === "object") ? it : {};

	// Diese Felder müssen pro Item stabil bleiben:
	const product = String(o.product || o.product_key || o.productKey || "").trim();
	const offer   = String(o.offer || "").trim();
	const article = String(o.article || "").trim();

	const title = String(o.title || o.name || article || "Artikel").trim();

	// Bild niemals aus irgendeinem "aktuellen Produkt" überschreiben:
	const image = String(o.image || "").trim();

	// Preise + Menge
	const q = Math.max(1, parseInt(String(o.quantity || 1), 10) || 1);

	const pg = toFloatDE(o.price_gross_unit);
	const pn = toFloatDE(o.price_net_unit);

	const selectionText = String(o.selection_text || "").trim();

	return {
	  ...o,
	  product,
	  offer,
	  article,
	  title,
	  image,
	  selection_text: selectionText,
	  quantity: q,
	  price_gross_unit: (pg === null ? null : pg),
	  price_net_unit:   (pn === null ? null : pn),

	  // optional “Lock”-Flag, falls andere Scripts es beachten:
	  __locked: true
	};
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

  function imgHtmlForItem(it){
	// checkout.php nutzt (wie cart.js) ../images/
	const img = String(it.image || "").trim();
	if(!img) return `<div class="small" style="padding:10px">Kein Bild</div>`;
	return `<img src="../images/${escapeHtml(img)}" alt="">`;
  }

  function render(){
	if(!elList || !elGross || !hidBuy || !hidOffer) return;

	// ✅ Cart laden + normalisieren + deepclone (keine Referenz-Probleme)
	const rawItems = loadCart();
	const items = rawItems.map(x => normalizeItem(deepClone(x)));

	// Hidden JSON für BUY + OFFER setzen (vom Freight-Script wird es geparst!)
	const orderPayload = { items: items };
	hidBuy.value   = JSON.stringify(orderPayload);
	hidOffer.value = JSON.stringify(orderPayload);

	// Meta
	const totalQty = items.reduce((a,it)=> a + Math.max(1, Number(it.quantity||1)), 0);
	if(elMeta){
	  elMeta.textContent = items.length
		? `${items.length} Position(en) · ${totalQty} Stück`
		: "—";
	}

	if(!items.length){
	  elList.innerHTML = `<div class="small">Warenkorb ist leer.</div>`;
	  elGross.textContent = "—";
	  if(elNet) elNet.textContent = "";
	  return;
	}

	// ✅ Render Summary wie in checkout.php CSS (.sum-row/.sum-img/.sum-title/.sum-meta/.sum-right)
	elList.innerHTML = items.map((it) => {
	  const meta = (it.selection_text || "").trim();
	  const offerTxt = (it.offer || "").trim();

	  const gross = lineGross(it);
	  const net = lineNet(it);

	  const qty = Math.max(1, Number(it.quantity || 1));

	  return `
		<div class="sum-row">
		  <div class="sum-left">
			<div class="sum-img">${imgHtmlForItem(it)}</div>
			<div>
			  <div class="sum-title">${escapeHtml(it.title || it.article || "Artikel")}</div>
			  <div class="sum-meta">
				<div><b>Artikelnr.:</b> ${escapeHtml(it.article || "")}</div>
				${offerTxt ? `<div><b>Angebot:</b> ${escapeHtml(offerTxt)}</div>` : ``}
				${meta ? `<div>${escapeHtml(meta)}</div>` : ``}
				<div><b>Menge:</b> ${escapeHtml(String(qty))}</div>
			  </div>
			</div>
		  </div>

		  <div class="sum-right">
			<div><b>${money(gross)}</b></div>
			${net !== null ? `<div class="net">${money(net)} ohne MwSt.</div>` : ``}
		  </div>
		</div>
	  `;
	}).join("");

	const sumG = total(items, lineGross);
	const sumN = total(items, lineNet);

	elGross.textContent = (sumG !== null) ? money(sumG) : "—";
	if(elNet){
	  elNet.textContent = (sumN !== null) ? (money(sumN) + " ohne MwSt.") : "";
	}
  }

  // ✅ zusätzlich: falls irgendein anderes Script später order_json überschreibt,
  // halten wir es stabil (aber ohne Spam)
  let lastSig = "";
  function tick(){
	const raw = localStorage.getItem(KEY) || "";
	if(raw === lastSig) return;
	lastSig = raw;
	render();
  }

  // Initial
  render();

  // Reagieren wenn Cart sich ändert (anderer Tab, etc.)
  window.addEventListener("storage", (e) => {
	if(e.key === KEY) render();
  });

  // Safety: poll (dein Checkout hat bereits intervals – wir bleiben leicht)
  setInterval(tick, 500);
})();