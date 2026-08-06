<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';
 
$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$boot = $repo->bootstrap();

$title = (string)($boot['content']['product']['title'] ?? 'Warenkorb');
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>REGATIX SHOP | Warenkorb </title>
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
