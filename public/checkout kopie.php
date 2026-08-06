<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Repository.php';

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$boot = $repo->bootstrap();
$cfg  = $repo->getConfig();

$title = (string)($boot['content']['product']['title'] ?? 'Checkout');
$shop  = (array)($cfg['shop'] ?? []);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Checkout · <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
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
	.grid2{ display:grid; grid-template-columns: 1.05fr .95fr; gap:14px; }
	@media (max-width: 980px){ .grid2{ grid-template-columns: 1fr; } }
	input[type="text"], input[type="email"], input[type="tel"], textarea, select{
	  width:100%; height:40px; border-radius:12px; border:1px solid var(--border);
	  padding:0 12px; background: rgba(0,0,0,.03); color: var(--text);
	}
	textarea{ height:auto; min-height:110px; padding:12px; }
	body.layout-pro input, body.layout-pro textarea, body.layout-pro select{
	  background: rgba(0,0,0,.25); color: var(--text);
	}
	.row{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
	@media (max-width: 720px){ .row{ grid-template-columns: 1fr; } }
	.warnbox{
	  border:1.5px solid rgba(255,77,77,.95);
	  border-radius: 18px;
	  padding:14px;
	  background: rgba(255,77,77,.06);
	}
	.warnbox h3{ margin:0 0 6px 0; }
	.warnbox .small{ color: var(--muted); }
	body.layout-pro .warnbox .small{ color: rgba(232,240,255,.65); }
	.sum-row{ display:flex; gap:12px; justify-content:space-between; align-items:flex-start; padding:10px 0; border-top:1px solid var(--border); }
	.sum-row:first-child{ border-top:0; padding-top:0; }
	.sum-left{ display:flex; gap:12px; align-items:flex-start; flex:1; min-width: 240px; }
	.sum-img{
	  width:90px; height:64px; border-radius: 12px; border: 1px solid var(--border);
	  background: rgba(0,0,0,.03); overflow:hidden; flex:0 0 auto;
	  display:flex; align-items:center; justify-content:center;
	}
	.sum-img img{ width:100%; height:100%; object-fit:cover; display:block; }
	body.layout-pro .sum-img{ background: rgba(0,0,0,.25); }
	.sum-title{ font-weight:800; }
	.sum-meta{ margin-top:6px; font-size: 13px; color: var(--muted); }
	.sum-right{ text-align:right; min-width: 180px; }
	.payment-methods label { font-weight: 500; font-size:1.08em; margin-bottom: 0.2em; display: block; cursor: pointer; }
  </style>
</head>
<body><div id="logo"><img alt="" height="80" src="https://www.regatix.com/media/regatixshop.png" width="506" />Ihr Regal-Shop<img alt="" height="200" src="https://www.regatix.com/media/REGATIX/SHOP_zeigt_nach_links.png" style="float: right;" width="114" /> </div>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Checkout</div>
		<div class="h-sub"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></div>
	  </div>
	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="cart.php">Zurück</a>
	  </div>
	</div>

	<div id="pv_checkout_error" class="small"></div>

	<div class="card" id="pv_offer_box" style="display:none">
	  <div class="card-h"><div class="card-title">Hinweis</div></div>
	  <div class="card-b">
		<div class="warnbox">
		  <h3>Netto-Summe über 1.500 €</h3>
		  <div class="small" style="margin-bottom:10px">
			Bitte fordern Sie ein dediziertes Angebot an das mit Sicherheit günstiger ist.
			Tragen Sie hier ihre E-Mail Adresse ein und drücken Sie absenden. Sie erhalten das Angebot in Kürze.
		  </div>
		  <form method="post" action="checkout_submit.php" id="pv_offer_form">
			<input type="hidden" name="mode" value="offer">
			<input type="hidden" name="order_json" id="pv_offer_order_json" value="">
			<div class="row">
			  <div>
				<label>E-Mail</label>
				<input type="email" name="email" id="pv_offer_email" placeholder="name@firma.de" required>
			  </div>
			  <div>
				<label>&nbsp;</label>
				<button class="btn" type="submit" style="width:100%; height:40px; font-weight:800">Angebot anfordern</button>
			  </div>
			</div>
		  </form>
		  <div class="small" style="margin-top:10px">Alternativ können Sie auch direkt kaufen.</div>
		</div>
	  </div>
	</div>

	<div class="grid2">
	  <div class="card">
		<div class="card-h"><div class="card-title">Kundendaten<br>Bitte vollständig ausfüllen</div>
		  <div class="small">
			<div style="display:flex; gap:10px; margin-bottom:18px">
			  <button class="btn" type="button" id="btn_register">Registrieren</button>
			  <button class="btn" type="button" id="btn_login">Anmelden</button>
			</div>
		  </div>
		</div>

		<div class="card-b">
		  <form method="post" action="checkout_submit.php" id="pv_buy_form">
			<input type="hidden" name="mode" value="buy">
			<input type="hidden" name="order_json" id="pv_buy_order_json" value="">

			<div class="row">
			  <div><label>Vorname</label><input type="text" name="first_name" required></div>
			  <div><label>Nachname</label><input type="text" name="last_name" required></div>
			</div>
			<div style="height:10px"></div>
			<div class="row">
			  <div><label>Firma (optional)</label><input type="text" name="company"></div>
			  <div><label>Telefon (optional)</label><input type="tel" name="phone"></div>
			</div>
			<div style="height:10px"></div>
			<div class="row">
			  <div><label>E-Mail</label><input type="email" name="email" id="checkout_email" required></div>
			</div>
			<div style="height:10px"></div>
			<div class="row">
			  <div><label>Straße / Nr.</label><input type="text" name="street" required></div>
			  <div><label>PLZ</label><input type="text" name="zip" required></div>
			</div>
			<div style="height:10px"></div>
			<div class="row">
			  <div><label>Ort</label><input type="text" name="city" required></div>
			  <div><label>Land</label><input type="text" name="country" value="Deutschland" required></div>
			</div>
			<div style="height:10px"></div>
			<!-- Bemerkung -->
			<label>Bemerkung (optional)</label>
			<textarea name="note" placeholder="z.B. Abholtermin, Rückfragen, etc."></textarea>
			<div style="height:16px"></div>
			<!-- Bezahlmethoden unter Bemerkung -->
			<div style="margin-bottom: 1.2em;">
			  <label style="font-weight:bold;display:block;margin-bottom:0.6em;">
				Bezahlmethode <span style="color:#e53e3e">*</span>
			  </label>
			  <div class="payment-methods" style="display:flex; flex-direction:column; gap:0.35em;">
				<label><input type="radio" name="payment" value="paypal" required> PayPal</label>
				<label><input type="radio" name="payment" value="bank_transfer"> Vorkasse (Überweisung)</label>
				<label><input type="radio" name="payment" value="cash_pickup"> Bar bei Abholung</label>
			  </div>
			</div>
			<div id="pv_payment_hint" class="small" style="margin-top:4px"></div>
			<div style="height:8px"></div>
			<button class="btn" type="submit" id="pv_buy_submit" style="width:100%; height:44px; font-weight:900" disabled>Direkt kaufen!</button>
			<button class="btn" id="pv_proforma_btn" type="button" style="width:100%; height:44px; font-weight:900; margin-top:10px">Proforma ansehen (HTML)</button>
		  </form>
		</div>
	  </div>
	  <div class="card">
		<div class="card-h"><div class="card-title">Zusammenfassung</div><div class="small" id="pv_sum_meta">—</div></div>
		<div class="card-b">
		  <div id="pv_sum_list"></div>
		  <div class="hr"></div>
		  <div>
			<div class="small">Summe (brutto)</div>
			<div class="price-gross" id="pv_sum_gross">—</div>
			<div class="net" id="pv_sum_net"></div>
		  </div>
		</div>
	  </div>
	</div>
  </div>
  <!-- Dummy-Shopdaten an JS -->
  <script>
	window.PV_SHOP = <?= json_encode($shop, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  </script>
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
  <!-- Zahlungsart muss gewählt sein, damit Kaufen-Button aktiv wird -->
  <script>
	function updateBuyButton() {
	  const checked = document.querySelector('input[name="payment"]:checked');
	  document.getElementById('pv_buy_submit').disabled = !checked;
	}
	document.querySelectorAll('input[name="payment"]').forEach(el => {
	  el.addEventListener('change', updateBuyButton);
	});
	updateBuyButton();
	document.getElementById('pv_buy_form').addEventListener('submit', function(e) {
	  if (!document.querySelector('input[name="payment"]:checked')) {
		alert("Bitte wählen Sie eine Bezahlmethode aus!");
		e.preventDefault();
	  }
	});
  </script>
  <script src="assets/checkout.js"></script>
  <!-- Registrieren Modal -->
  <div id="modal_register" class="modal" style="display:none">
	<div class="modal-content">
	  <span class="close" id="close_register">&times;</span>
	  <h2>Registrieren</h2>
	  <form id="form_register">
		<input type="text" name="first_name" placeholder="Vorname" required><br>
		<input type="text" name="last_name" placeholder="Nachname" required><br>
		<input type="text" name="company" placeholder="Firma (optional)"><br>
		<input type="tel"  name="phone" placeholder="Telefon (optional)"><br>
		<input type="email" name="email" placeholder="E-Mail" required><br>
		<input type="text" name="street" placeholder="Straße / Nr." required><br>
		<input type="text" name="zip" placeholder="PLZ" required><br>
		<input type="text" name="city" placeholder="Ort" required><br>
		<input type="text" name="country" placeholder="Land" value="Deutschland" required><br>
		<input type="password" name="password" placeholder="Passwort" required><br>
		<input type="password" name="password2" placeholder="Passwort wiederholen" required><br>
		<button class="btn" type="submit">Registrieren</button>
		<div id="register_error" style="color:#e53e3e; margin-top:8px"></div>
	  </form>
	</div>
  </div>
  <!-- Login Modal -->
  <div id="modal_login" class="modal" style="display:none">
	<div class="modal-content">
	  <span class="close" id="close_login">&times;</span>
	  <h2>Anmelden</h2>
	  <form id="form_login">
		<input type="email" name="email" placeholder="E-Mail" required><br>
		<input type="password" name="password" placeholder="Passwort" required><br>
		<button class="btn" type="submit">Anmelden</button>
		<div id="login_error" style="color:#e53e3e; margin-top:8px"></div>
	  </form>
	</div>
  </div>
  <style>
	.modal { position:fixed; z-index:999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,.4); display:flex; align-items:center; justify-content:center; }
	.modal-content { background:#fff; border-radius:16px; padding:32px 24px 18px 24px; position:relative; min-width:300px; box-shadow:0 4px 28px rgba(0,0,0,.18);}
	.close { position:absolute; right:18px; top:10px; font-size:2em; color:#888; cursor:pointer; }
  </style>
  <script>
	function showModal(id) { document.getElementById(id).style.display='flex'; }
	function hideModal(id) { document.getElementById(id).style.display='none'; }
	document.getElementById('btn_register').onclick = ()=> showModal('modal_register');
	document.getElementById('btn_login').onclick = ()=> showModal('modal_login');
	document.getElementById('close_register').onclick = ()=> hideModal('modal_register');
	document.getElementById('close_login').onclick = ()=> hideModal('modal_login');

	// Registrierung
	document.getElementById('form_register').onsubmit = function(e){
	  e.preventDefault();
	  let fd = new FormData(this);
	  fetch('kunden_api.php', {
		method: 'POST',
		body: new URLSearchParams([...fd, ['action', 'register']])
	  })
	  .then(res=>res.json()).then(data=>{
		if(data.ok){
		  hideModal('modal_register');
		  fillCheckoutFields(data.user);
		}else{
		  document.getElementById('register_error').textContent = data.error||'Fehler';
		}
	  });
	};
	// Login
	document.getElementById('form_login').onsubmit = function(e){
	  e.preventDefault();
	  let fd = new FormData(this);
	  fetch('kunden_api.php', {
		method: 'POST',
		body: new URLSearchParams([...fd, ['action', 'login']])
	  })
	  .then(res=>res.json()).then(data=>{
		if(data.ok){
		  hideModal('modal_login');
		  fillCheckoutFields(data.user);
		}else{
		  document.getElementById('login_error').textContent = data.error||'Fehler';
		}
	  });
	};
	// Felder ausfüllen
	function fillCheckoutFields(user){
	  if(!user) return;
	  // Setze explizit die Email, auch wenn das Feld bereits existiert
	  if(user['email']){
		// 1. Suche direkt nach ID, dann nach name
		var emailInput = document.getElementById('checkout_email') || document.querySelector('input[name="email"]');
		if(emailInput) emailInput.value = user['email'];
	  }
	  // Restliche Felder
	  ['first_name','last_name','company','phone','street','zip','city','country'].forEach(f=>{
		if(user[f] && document.querySelector(`[name="${f}"]`)) {
		  document.querySelector(`[name="${f}"]`).value = user[f];
		}
	  });
	}
  </script>
  
 <div id="footer"><p>REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull; Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
	 Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull; Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr <br><a href="https://www.regatix.com/pages/start/impressum.php" target="_blank">Impressum</a> | <a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank">Datenschutz</a> | <a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank">Versandbedingungen</a> | <a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Wideruf</a> | <a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank">SHOP Bedingungen</a></p>
 </div>
  
</body>
</html>
