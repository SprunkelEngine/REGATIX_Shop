<?php
declare(strict_types=1);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Frachtkosten Test</title>
  <style>
	body{ font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; padding:24px; }
	.box{ max-width:560px; margin:0 auto; padding:18px; border:1px solid #ddd; border-radius:14px; }
	label{ display:block; font-weight:700; margin:12px 0 6px; }
	input{ width:100%; height:42px; padding:0 12px; border:1px solid #ccc; border-radius:10px; font-size:16px; }
	button{ margin-top:14px; height:42px; padding:0 16px; border-radius:10px; border:1px solid #333; background:#111; color:#fff; font-weight:800; cursor:pointer; }
	button:disabled{ opacity:.6; cursor:not-allowed; }
	.out{ margin-top:16px; padding:14px; border-radius:12px; background:#f7f7f7; border:1px solid #e3e3e3; }
	.row{ display:flex; gap:12px; flex-wrap:wrap; }
	.pill{ padding:6px 10px; border-radius:999px; background:#fff; border:1px solid #ddd; font-size:13px; }
	.big{ font-size:20px; font-weight:900; margin:6px 0; }
	.small{ color:#555; font-size:13px; margin-top:6px; }
	.err{ color:#b00020; font-weight:800; }
	code{ font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size:12px; }
  </style>
</head>
<body>
  <div class="box">
	<h1 style="margin:0 0 10px;">Frachtkosten Test</h1>
	<div class="small">Fragt <code>ajax_freightcost.php</code> ab und zeigt Netto/Brutto an.</div>

	<label for="country">Land (z.B. DE)</label>
	<input id="country" value="DE" autocomplete="off">

	<label for="zip">PLZ</label>
	<input id="zip" placeholder="z.B. 01069" autocomplete="off">

	<button id="btn" type="button">Abfragen</button>

	<div id="result" class="out" style="display:none;">
	  <div class="row" style="margin-bottom:10px;">
		<span class="pill" id="pill_ok">—</span>
		<span class="pill" id="pill_found">—</span>
		<span class="pill" id="pill_zone">—</span>
		<span class="pill" id="pill_cat" style="display:none;">—</span>
	  </div>

	  <div>
		<div class="small">Brutto</div>
		<div class="big" id="brutto">—</div>
	  </div>

	  <div style="margin-top:10px;">
		<div class="small">Netto</div>
		<div class="big" id="netto">—</div>
	  </div>

	  <div class="small" id="meta" style="margin-top:10px;"></div>

	  <details style="margin-top:12px;">
		<summary>Raw JSON</summary>
		<pre id="raw" style="white-space:pre-wrap; margin-top:8px;"></pre>
	  </details>
	</div>

	<div id="error" class="out" style="display:none;">
	  <div class="err">Fehler</div>
	  <div id="errtext" class="small"></div>
	</div>
  </div>

  <script>
	const zipEl = document.getElementById('zip');
	const countryEl = document.getElementById('country');
	const btn = document.getElementById('btn');

	const resultBox = document.getElementById('result');
	const errorBox = document.getElementById('error');
	const errText = document.getElementById('errtext');

	const pillOk = document.getElementById('pill_ok');
	const pillFound = document.getElementById('pill_found');
	const pillZone = document.getElementById('pill_zone');
	const pillCat = document.getElementById('pill_cat');

	const bruttoEl = document.getElementById('brutto');
	const nettoEl = document.getElementById('netto');
	const metaEl = document.getElementById('meta');
	const rawEl = document.getElementById('raw');

	function showError(msg){
	  resultBox.style.display = 'none';
	  errorBox.style.display = 'block';
	  errText.textContent = msg || 'Unbekannter Fehler';
	}

	function showResult(data){
	  errorBox.style.display = 'none';
	  resultBox.style.display = 'block';

	  pillOk.textContent = 'ok: ' + String(!!data.ok);
	  pillFound.textContent = 'found: ' + String(!!data.found);

	  pillZone.textContent = 'zone: ' + (data.zone || '—');

	  if (data.category) {
		pillCat.style.display = 'inline-block';
		pillCat.textContent = 'category: ' + data.category;
	  } else {
		pillCat.style.display = 'none';
	  }

	  bruttoEl.textContent = data.brutto || '—';
	  nettoEl.textContent = data.netto || '—';
	  metaEl.textContent = data.meta || '';

	  rawEl.textContent = JSON.stringify(data, null, 2);
	}

	async function query(){
	  const zip = (zipEl.value || '').trim();
	  const country = (countryEl.value || '').trim();
	  if (!zip || !country) return showError('Bitte Land und PLZ eingeben.');

	  btn.disabled = true;
	  btn.textContent = 'Lade…';

	  try{
		const url = 'ajax_freightcost.php?zip=' + encodeURIComponent(zip) + '&country=' + encodeURIComponent(country);
		const res = await fetch(url, { cache: 'no-store' });
		const text = await res.text();

		let data = null;
		try { data = JSON.parse(text); } catch(e) {
		  throw new Error('Antwort ist kein JSON: ' + text.slice(0,200));
		}

		if (!res.ok || !data.ok) {
		  throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
		}

		showResult(data);

	  }catch(e){
		showError(e && e.message ? e.message : String(e));
	  }finally{
		btn.disabled = false;
		btn.textContent = 'Abfragen';
	  }
	}

	btn.addEventListener('click', query);
	zipEl.addEventListener('keydown', (e)=>{ if(e.key === 'Enter') query(); });
	countryEl.addEventListener('keydown', (e)=>{ if(e.key === 'Enter') query(); });
  </script>
</body>
</html>
