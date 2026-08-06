<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Repository.php';

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$boot = $repo->bootstrap();
$cfg  = $repo->getConfig();

$title = (string)($boot['content']['product']['title'] ?? 'Checkout');
$shop  = (array)($cfg['shop'] ?? []);

// ✅ PayPal config aus shop.paypal (Live/Sandbox)
$paypal    = (array)($shop['paypal'] ?? []);
$ppEnabled = !empty($paypal['enabled']);

// optional: 'live' oder 'sandbox' (Default live)
$ppMode = strtolower((string)($paypal['mode'] ?? 'live'));

// getrennte IDs (empfohlen)
$ppClientIdLive    = (string)($paypal['client_id_live'] ?? '');
$ppClientIdSandbox = (string)($paypal['client_id_sandbox'] ?? '');

// Fallback: falls du bisher nur client_id hattest
$ppClientIdFallback = (string)($paypal['client_id'] ?? '');

// aktive Client-ID ermitteln
$ppClientId = ($ppMode === 'sandbox')
  ? ($ppClientIdSandbox !== '' ? $ppClientIdSandbox : $ppClientIdFallback)
  : ($ppClientIdLive    !== '' ? $ppClientIdLive    : $ppClientIdFallback);
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
  <title>REGAITX SHOP | Checkout </title>
  <link rel="stylesheet" href="assets/styles.css">

  <?php if ($ppEnabled && $ppClientId !== ''): ?>
	<!-- ✅ PayPal JS SDK -->
	<script src="https://www.paypal.com/sdk/js?client-id=<?= htmlspecialchars($ppClientId, ENT_QUOTES, 'UTF-8') ?>&currency=EUR&intent=capture"></script>
  <?php endif; ?>

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

	input[type="text"], input[type="email"], input[type="tel"], input[type="password"], textarea, select{
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

	/* Modals */
	.modal { position:fixed; z-index:99999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,.4); display:none; align-items:flex-start; justify-content:center; padding-top:4vh; }
	.modal-content { background:#fff; border-radius:16px; padding:32px 24px 18px 24px; position:relative; min-width:320px; max-width:520px; width:92vw; box-shadow:0 4px 28px rgba(0,0,0,.18); }
	body.layout-pro .modal-content{ background:#121827; color:var(--text); border:1px solid rgba(255,255,255,.10); }
	.modal-content input{ width:100%; box-sizing:border-box; margin:0 0 10px 0; }
	.modal-content input[type="password"]{ height:52px; font-size:16px; padding:0 14px; border-radius:12px; }
	.close { position:absolute; right:18px; top:10px; font-size:2em; color:#888; cursor:pointer; background:transparent; border:none; }
	body.layout-pro .close{ color:#9ab0c7; }

	.small{ font-size:13px; color:var(--muted); }
	body.layout-pro .small{ color:rgba(232,240,255,.65); }
	.err{ color:#e53e3e; font-weight:700; }
	.ok{ color:var(--accent); font-weight:700; }

	.top-userbar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
	.pill{ border:1px solid var(--border); padding:6px 10px; border-radius:999px; font-size:13px; }

	#freight_wrap.is-pickup {
	  opacity: .45;
	  filter: grayscale(1);
	}
	#freight_wrap.is-pickup * {
	  pointer-events: none;
	}
  </style>
</head>

<body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-W5WRQLFN"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

  <!-- Logo (bereinigt) -->
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
		<div class="h-title">Checkout</div>
	   </div>
	  <div class="top-userbar">
		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="cart.php">Zurück</a>

		<!-- User UI -->
		<span class="pill" id="pv_user_badge" style="display:none;"></span>
		<button class="btn" type="button" id="btn_profile" style="display:none;">Profil</button>
		<button class="btn" type="button" id="btn_logout" style="display:none;background:#eee;color:#333;">Abmelden</button>
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
			<input type="hidden" name="discount_code" id="pv_offer_discount_code" value="">
			<input type="hidden" name="discount_note" id="pv_offer_discount_note" value="">


			<!-- ✅ Frachtkosten Hidden Fields (OFFER) -->
			<input type="hidden" name="freight_gross" id="pv_offer_freight_gross" value="0">
			<input type="hidden" name="freight_net"   id="pv_offer_freight_net" value="0">
			<input type="hidden" name="freight_zone"  id="pv_offer_freight_zone" value="">

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
		<div class="card-h">
		  <div class="card-title">Kundendaten<br>Bitte vollständig ausfüllen</div>
		  <div class="small">
			<div style="display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;">
			  <button class="btn" type="button" id="btn_register">Registrieren</button>
			  <button class="btn" type="button" id="btn_login">Anmelden</button>
			  <span class="small" id="pv_auth_hint" style="align-self:center;"></span>
			</div>
		  </div>
		</div>

		<div class="card-b">
		  <!-- ✅ Checkout-Felder werden bei Login automatisch befüllt -->
		  <form method="post" action="checkout_submit.php" id="pv_buy_form" autocomplete="off">
			<input type="hidden" name="mode" value="buy">
			<input type="hidden" name="order_json" id="pv_buy_order_json" value="">
			<input type="hidden" name="discount_code" id="pv_discount_code" value="">
 			<input type="hidden" name="discount_note" id="pv_discount_note" value="">


			<!-- ✅ Frachtkosten Hidden Fields (BUY) -->
			<input type="hidden" name="freight_gross" id="pv_freight_gross" value="0">
			<input type="hidden" name="freight_net"   id="pv_freight_net" value="0">
			<input type="hidden" name="freight_zone"  id="pv_freight_zone" value="">

			<div class="row">
			  <div><label>Vorname</label><input type="text" name="first_name" required value=""></div>
			  <div><label>Nachname</label><input type="text" name="last_name" required value=""></div>
			</div>
			<div style="height:10px"></div>
			
			<div class="row">
			  <div><label>Firma (optional)</label><input type="text" name="company" value=""></div>
<div><span><strong><label><span style="color:#c0392b;">Telefon für die Zustellung (zwingend)&nbsp;</span></label></strong></span><input name="ship_phone" type="tel" value="" /></div>
			</div>
			<div style="height:10px"></div>
			
			<div class="row">
			  <div><label>E-Mail</label><input type="email" name="email" id="checkout_email" required value=""></div>
			</div>
			<div style="height:10px"></div>
			
			<div class="row">
			  <div><label>Straße / Nr.</label><input type="text" name="street" required value=""></div>
			  <div><label>PLZ</label><input type="text" name="zip" required value=""></div>
			</div>
			<div style="height:10px"></div>
			
			<div style="height:10px"></div>

			<div class="row">
			  <div><label>Ort</label><input type="text" name="city" required value=""></div>
			  <div><label>Land</label><select name="country" required>
				<option value="DE" selected>🇩🇪 DE</option>
				<option value="AT">🇦🇹 AT</option>
				<option value="CH">🇨🇭 CH</option>
				<option value="NL">🇳🇱 NL</option>
				<option value="BE">🇧🇪 BE</option>
				<option value="LU">🇱🇺 LU</option>
				<option value="FR">🇫🇷 FR</option>
				<option value="IT">🇫🇷 IT</option>
				<option value="DK">🇩🇰 DK</option>
				<option value="SE">🇸🇪 SE</option>
				<option value="NO">🇳🇴 NO</option>
				<option value="PL">🇵🇱 PL</option>
				<option value="CZ">🇨🇿 CZ</option>
			  </select>
</div>
			</div>

<!-- Versandkosten / PLZ-Abfrage -->
			<div class="freight-field-wrap" id="freight_wrap"><br>
			  <label>Frachtkosten</label>
			  <div class="sum-meta" id="freight_meta">Bitte geben Sie die PLZ des Empfängers ein.</div><br>
			  <div class="freight-brutto" id="freight_brutto">—</div>
			  <div class="freight-netto" id="freight_netto"></div><br>
			</div>

<!-- Alternative Lieferadresse (aufklappbar) -->
			<div class="alt-ship-wrap" style="margin-top:10px;">
			  <label style="display:flex;align-items:center;gap:10px;font-weight:800;">
				<input type="checkbox" id="pv_ship_alt_toggle" name="ship_use_alt" value="1">
				Alternative Lieferadresse
			  </label>

			  <div id="pv_ship_alt_box" style="display:none; margin-top:10px; padding:12px; border:1px solid var(--border); border-radius:14px; background:rgba(0,0,0,.02);">
				<div class="row">
				  <div><label>Firma (optional)</label><input type="text" name="ship_company" value=""></div>
<div><span><strong><label><span style="color:#c0392b;">Telefon für die Zustellung (zwingend)&nbsp;</span></label></strong></span><input name="ship_phone" type="tel" value="" /></div>

				</div>
				<div style="height:10px"></div>

				<div class="row">
				  <div><label>Vorname</label><input type="text" name="ship_first_name" value=""></div>
				  <div><label>Nachname</label><input type="text" name="ship_last_name" value=""></div>
				</div>
				<div style="height:10px"></div>

				<div class="row">
				  <div><label>Straße / Nr.</label><input type="text" name="ship_street" value=""></div>
				  <div><label>PLZ</label><input type="text" name="ship_zip" value=""></div>
				</div>
				<div style="height:10px"></div>

				<div class="row">
				  <div><label>Ort</label><input type="text" name="ship_city" value=""></div>
				  <div>
					<label>Land</label>
					<select name="ship_country">
					  <option value="DE" selected>🇩🇪 DE</option>
					  <option value="AT">🇦🇹 AT</option>
					  <option value="CH">🇨🇭 CH</option>
					  <option value="NL">🇳🇱 NL</option>
					  <option value="BE">🇧🇪 BE</option>
					  <option value="LU">🇱🇺 LU</option>
					  <option value="FR">🇫🇷 FR</option>
					  <option value="IT">🇮🇹 IT</option>
					  <option value="DK">🇩🇰 DK</option>
					  <option value="SE">🇸🇪 SE</option>
					  <option value="NO">🇳🇴 NO</option>
					  <option value="PL">🇵🇱 PL</option>
					  <option value="CZ">🇨🇿 CZ</option>
					</select>
				  </div>
				</div>

				<div class="small" style="margin-top:10px;">
				  Wenn leer gelassen, wird automatisch die Rechnungsadresse verwendet.
				</div>
			  </div>
			</div><br>

			<label>Bemerkung (optional)</label>
			<textarea name="note" placeholder="z.B. Abholtermin, Rückfragen, etc."></textarea>
			<div style="height:16px"></div>

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

			<div style="margin-bottom: 1.2em;">
			  <label style="font-weight:bold;display:block;margin-bottom:0.6em;">
				Rabattcode (optional)
			  </label>
			  <div style="display:flex; gap:10px; align-items:center;">
				<input type="text" id="pv_discount_input" placeholder="z.B. WELCOME10" style="flex:1; height:40px;">
				<button class="btn" type="button" id="pv_discount_apply" style="height:40px; font-weight:800">
				  Anwenden
				</button>
			  </div>
			  <div class="small" id="pv_discount_msg" style="margin-top:6px; opacity:.9"></div>
			</div>

			<?php if ($ppEnabled && $ppClientId !== ''): ?>
			  <div class="small" style="margin-top:8px; opacity:.85"></div>
			<?php endif; ?>

			<div id="pv_payment_hint" class="small" style="margin-top:4px"></div>

			<div id="pv_paypal_area" style="display:none; margin-top:12px">
			  <div id="paypal-button-container"></div>
			  <div class="small" id="pv_paypal_msg" style="margin-top:6px"></div>
			</div>

			<div style="height:8px"></div>
			<button class="btn" type="submit" id="pv_buy_submit" style="width:100%; height:44px; font-weight:900" disabled>Direkt kaufen!</button>

		  </form>
		</div>
	  </div>

	  <div class="card">
		<div class="card-h"><div class="card-title">Zusammenfassung</div><div class="small" id="pv_sum_meta">—</div></div>
		<div class="card-b">
		  <div id="pv_sum_list"></div>
		  <!-- ✅ Rabatt-Anzeige (Code + Notiz) -->
		  <div id="pv_discount_checkout" class="small" style="margin-top:10px; display:none;"></div>
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

  <script>
	window.PV_SHOP = <?= json_encode($shop, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
	window.PV_IS_LOGGED_IN = false;
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

 <!-- ✅ Zahlungsart + PayPal Logik (MIT Telefon-Pflicht: PayPal-Button deaktiviert bis Telefon gesetzt) -->
 <script>
 (function(){
   const form      = document.getElementById('pv_buy_form');
   const submitBtn = document.getElementById('pv_buy_submit');

   const ppArea = document.getElementById('pv_paypal_area');
   const ppMsg  = document.getElementById('pv_paypal_msg');

   // ✅ Wichtig: erlaubt Submit NUR nachdem PayPal onApprove erfolgreich war
   let paypalApproved = false;

   function selectedPayment(){
	 return document.querySelector('input[name="payment"]:checked')?.value || '';
   }

   // ✅ Telefon-Gate für PayPal
   const phoneEl = form ? form.querySelector('input[name="phone"]') : null;
   let ppActionsRef = null;

   function phoneOk(){
	 return !!(phoneEl && phoneEl.value.trim().length > 0);
   }

   function syncPayPalPhoneGate(){
	 // nur relevant wenn PayPal gewählt ist
	 if (selectedPayment() !== 'paypal') {
	   if (ppMsg) ppMsg.textContent = '';
	   return;
	 }

	 // Buttons noch nicht init → nur Hinweistext setzen
	 if (!ppActionsRef) {
	   if (!phoneOk() && ppMsg) ppMsg.textContent = 'Bitte Telefon für die Zustellung eintragen, dann ist PayPal verfügbar.';
	   else if (ppMsg) ppMsg.textContent = '';
	   return;
	 }

	 if (phoneOk()){
	   ppActionsRef.enable();
	   if (ppMsg) ppMsg.textContent = '';
	 } else {
	   ppActionsRef.disable();
	   if (ppMsg) ppMsg.textContent = 'Bitte Telefon für die Zustellung eintragen, dann ist PayPal verfügbar.';
	 }
   }

   // live reagieren
   if (phoneEl) {
	 ['input','change','blur'].forEach(evt => phoneEl.addEventListener(evt, syncPayPalPhoneGate));
   }

   function getGrossAmountFromUI(){
	 const el = document.getElementById('pv_sum_gross');
	 const t  = (el?.textContent || '').trim();

	 let s = t.replace(/[^\d,.\-]/g, '');
	 if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '').replace(',', '.');
	 else s = s.replace(',', '.');

	 const n = parseFloat(s);
	 if (!isFinite(n) || n <= 0) return '';
	 return n.toFixed(2);
   }

   function updateUI(){
	 const pay = selectedPayment();
	 const hasChoice = !!pay;

	 if (submitBtn) submitBtn.disabled = !hasChoice;

	 const isPP = pay === 'paypal';
	 if (ppArea) ppArea.style.display = isPP ? 'block' : 'none';
	 if (submitBtn) submitBtn.style.display = isPP ? 'none' : 'block';

	 if (isPP) {
	   renderPayPalButtonsOnce();
	   syncPayPalPhoneGate();
	 }
   }

   let ppRendered = false;

   function renderPayPalButtonsOnce(){
	 if (ppRendered) return;

	 if (!window.paypal) {
	   if (ppMsg) ppMsg.textContent = 'PayPal SDK ist nicht geladen (Client-ID/Config prüfen).';
	   return;
	 }

	 const container = document.getElementById('paypal-button-container');
	 if (!container) return;

	 ppRendered = true;

	 paypal.Buttons({
	   // ✅ echtes Disable/Enable über PayPal Actions
	   onInit: (data, actions) => {
		 ppActionsRef = actions;
		 syncPayPalPhoneGate();
	   },

	   createOrder: async () => {
		 // ✅ Harter Block: ohne Telefon keine Order
		 if (!phoneOk()){
		   syncPayPalPhoneGate();
		   throw new Error('Telefon fehlt');
		 }

		 if (ppMsg) ppMsg.textContent = '';

		 const orderJson = document.getElementById('pv_buy_order_json')?.value || '';
		 if (!orderJson) {
		   if (ppMsg) ppMsg.textContent = 'Warenkorb fehlt (order_json ist leer).';
		   throw new Error('order_json leer');
		 }

		 const discountCode = document.getElementById('pv_discount_code')?.value || '';
		 const amount = getGrossAmountFromUI();

		 const res = await fetch('paypal_create_order.php', {
		   method: 'POST',
		   headers: {'Content-Type':'application/json'},
		   body: JSON.stringify({ order_json: orderJson, discount_code: discountCode, amount: amount })
		 });

		 const text = await res.text();
		 let data = null;
		 try { data = JSON.parse(text); } catch(e) {
		   throw new Error('Server antwortet nicht mit JSON. Status ' + res.status + ' – ' + text.slice(0,200));
		 }

		 if (!res.ok || !data.id) {
		   throw new Error((data.error || 'PayPal CreateOrder fehlgeschlagen') + (data.detail ? (' – ' + data.detail) : ''));
		 }

		 return data.id;
	   },

	   onApprove: async (data) => {
		 if (ppMsg) ppMsg.textContent = 'Zahlung wird abgeschlossen …';

		 const res = await fetch('paypal_capture_order.php', {
		   method: 'POST',
		   headers: {'Content-Type':'application/json'},
		   body: JSON.stringify({ order_id: data.orderID })
		 });

		 const text = await res.text();
		 let cap = null;
		 try { cap = JSON.parse(text); } catch(e) {
		   throw new Error('Server antwortet nicht mit JSON. Status ' + res.status + ' – ' + text.slice(0,200));
		 }

		 if (!res.ok || cap.status !== 'COMPLETED') {
		   if (ppMsg) ppMsg.textContent = 'PayPal Zahlung nicht abgeschlossen.';
		   throw new Error((cap.error || 'PayPal Capture fehlgeschlagen') + (cap.detail ? (' – ' + cap.detail) : ''));
		 }

		 // ✅ PayPal-IDs ins Formular
		 const h1 = document.createElement('input');
		 h1.type='hidden'; h1.name='paypal_order_id'; h1.value=data.orderID;
		 form.appendChild(h1);

		 const h2 = document.createElement('input');
		 h2.type='hidden'; h2.name='paypal_capture_id'; h2.value=cap.capture_id || '';
		 form.appendChild(h2);

		 if (ppMsg) ppMsg.textContent = 'PayPal OK – Bestellung wird gespeichert …';

		 // ✅ JETZT Submit erlauben
		 paypalApproved = true;

		 // ✅ requestSubmit triggert Validation + submit handler (jetzt erlaubt)
		 if (typeof form.requestSubmit === 'function') form.requestSubmit();
		 else form.submit();
	   },

	   onCancel: () => { if (ppMsg) ppMsg.textContent = 'PayPal abgebrochen.'; },
	   onError: (err) => {
		 // Telefon-Fehlermeldung sauber anzeigen, falls createOrder blockt
		 if (!phoneOk()) {
		   if (ppMsg) ppMsg.textContent = 'Bitte Telefon für die Zustellung eintragen, dann ist PayPal verfügbar.';
		   return;
		 }
		 if (ppMsg) ppMsg.textContent = 'PayPal Fehler: ' + (err?.message || err);
	   }
	 }).render('#paypal-button-container');
   }

   document.querySelectorAll('input[name="payment"]').forEach(el => el.addEventListener('change', () => {
	 // falls User PayPal auswählt, aber später umstellt
	 if (selectedPayment() !== 'paypal') paypalApproved = false;
	 updateUI();
	 syncPayPalPhoneGate();
   }));

   // ✅ WICHTIG: Submit nur blocken, wenn PayPal NICHT genehmigt ist
   form.addEventListener('submit', function(e){
	 if (!selectedPayment()) {
	   alert("Bitte wählen Sie eine Bezahlmethode aus!");
	   e.preventDefault();
	   return;
	 }

	 // Nicht-PayPal → normaler Submit
	 if (selectedPayment() !== 'paypal') return;

	 // ✅ zusätzlich absichern: Telefon muss da sein
	 if (!phoneOk()){
	   e.preventDefault();
	   if (ppMsg) ppMsg.textContent = 'Bitte Telefon für die Zustellung eintragen, dann ist PayPal verfügbar.';
	   syncPayPalPhoneGate();
	   return;
	 }

	 // PayPal → nur erlauben wenn onApprove schon OK war
	 if (!paypalApproved) {
	   e.preventDefault();
	   if (ppMsg) ppMsg.textContent = 'Bitte den PayPal-Button verwenden.';
	   return;
	 }
   });

   updateUI();
 })();
 </script>

  <script src="assets/checkout.js"></script>

  <!-- ✅ Rabattcode-Logik + ✅ Anzeige in "Zusammenfassung" -->
 <script>
 (function(){
   const input = document.getElementById('pv_discount_input');
   const btn   = document.getElementById('pv_discount_apply');
   const msg   = document.getElementById('pv_discount_msg');
 
   const hidCode     = document.getElementById('pv_discount_code');
   const hidNote     = document.getElementById('pv_discount_note');
   const hidOfferCode= document.getElementById('pv_offer_discount_code');
   const hidOfferNote= document.getElementById('pv_offer_discount_note');
 
   const checkoutInfo = document.getElementById('pv_discount_checkout');
 
   function setMsg(text, ok){
	 if (!msg) return;
	 msg.textContent = text || '';
	 msg.style.color = ok ? 'var(--accent)' : '#e53e3e';
   }
 
   function setCheckoutInfo(code, note){
	 if (!checkoutInfo) return;
 
	 const c = String(code || '').trim();
	 const n = String(note || '').trim();
 
	 if (!c) {
	   checkoutInfo.style.display = 'none';
	   checkoutInfo.textContent = '';
	   return;
	 }
 
	 checkoutInfo.style.display = 'block';
	 checkoutInfo.textContent = 'Aktionscode aktiv: ' + c + (n ? (' – ' + n) : '');
   }
 
   async function applyCode(){
	 const code = (input?.value || '').trim();
	 const orderJson = document.getElementById('pv_buy_order_json')?.value || '';
 
	 // Entfernen
	 if (!code) {
	   if (hidCode) hidCode.value = '';
	   if (hidNote) hidNote.value = '';
	   if (hidOfferCode) hidOfferCode.value = '';
	   if (hidOfferNote) hidOfferNote.value = '';
	   setMsg('Rabattcode entfernt.', true);
	   setCheckoutInfo('', '');
	   return;
	 }
 
	 if (!orderJson) {
	   setMsg('Warenkorb fehlt (order_json ist leer).', false);
	   return;
	 }
 
	 try{
	   const res = await fetch('discount_preview.php', {
		 method: 'POST',
		 headers: {'Content-Type':'application/json'},
		 body: JSON.stringify({ order_json: orderJson, discount_code: code })
	   });
 
	   const data = await res.json().catch(()=>null);
 
	   if (!res.ok || !data || !data.ok) {
		 setMsg((data && data.error) ? data.error : 'Rabattcode ungültig.', false);
		 return;
	   }
 
	   const finalCode = (data.code || code).trim();
	   const note      = (data.note || '').trim();
 
	   // ✅ hidden setzen (BUY + OFFER)
	   if (hidCode) hidCode.value = finalCode;
	   if (hidNote) hidNote.value = note;
	   if (hidOfferCode) hidOfferCode.value = finalCode;
	   if (hidOfferNote) hidOfferNote.value = note;
 
	   // ✅ Anzeige Text: Code + Rabatt + Notiz
	   const discountTxt = (data.discount_gross || '0.00');
	   setMsg(
		 'Code akzeptiert: ' + finalCode +
		 (note ? (' – ' + note) : '') +
		 ' · Rabatt: ' + discountTxt + ' €',
		 true
	   );
 
	   // ✅ Anzeige im Checkout (Zusammenfassung)
	   setCheckoutInfo(finalCode, note);
 
	   // optional: Summe aktualisieren (wie vorher)
	   const sumEl = document.getElementById('pv_sum_gross');
	   if (sumEl && data.gross_after) sumEl.textContent = data.gross_after.replace('.', ',') + ' €';
 
	 }catch(e){
	   setMsg('Fehler beim Prüfen des Codes.', false);
	 }
   }
 
   if (btn) btn.addEventListener('click', applyCode);
 
   // ✅ beim Laden: falls schon aktiv (z.B. zurück navigiert), anzeigen
   setCheckoutInfo(hidCode?.value || '', hidNote?.value || '');
 })();
 </script>


  <!-- ===========================
	   MODALS: REGISTER / LOGIN / PROFILE
	   ✅ Login & Register bleiben immer LEER (kein Prefill)
	   =========================== -->

  <!-- Register Modal -->
  <div id="modal_register" class="modal">
	<div class="modal-content">
	  <button class="close" type="button" id="close_register">&times;</button>
	  <h2>Registrieren</h2>
	  <form id="form_register" autocomplete="off">
		<input type="text" name="first_name" placeholder="Vorname" required value=""><br>
		<input type="text" name="last_name" placeholder="Nachname" required value=""><br>
		<input type="text" name="company" placeholder="Firma (optional)" value=""><br>
		<input type="tel"  name="phone" placeholder="Telefon für die Zustellung (zwingend)" value=""><br>

		<input type="email" name="email" id="reg_email" placeholder="E-Mail" required value=""><br>
		<input type="email" name="email_confirm" id="reg_email_confirm" placeholder="E-Mail bestätigen" required value=""><br>

		<input type="text" name="street" placeholder="Straße / Nr." required value=""><br>
		<input type="text" name="zip" placeholder="PLZ" required value=""><br>
		<input type="text" name="city" placeholder="Ort" required value=""><br>
		<select name="country" required>
		  <option value="DE" selected>🇩🇪 DE</option>
		  <option value="AT">🇦🇹 AT</option>
		  <option value="CH">🇨🇭 CH</option>
		  <option value="NL">🇳🇱 NL</option>
		  <option value="BE">🇧🇪 BE</option>
		  <option value="LU">🇱🇺 LU</option>
		  <option value="FR">🇫🇷 FR</option>
		  <option value="IT">🇮🇹 IT</option>
		  <option value="DK">🇩🇰 DK</option>
		  <option value="SE">🇸🇪 SE</option>
		  <option value="NO">🇳🇴 NO</option>
		  <option value="PL">🇵🇱 PL</option>
		  <option value="CZ">🇨🇿 CZ</option>
		</select><br>

		<input type="password" name="password" id="reg_pw" placeholder="Passwort" required value=""><br>
		<input type="password" name="password2" id="reg_pw2" placeholder="Passwort wiederholen" required value=""><br>

		<button class="btn" type="submit">Registrieren</button>
		<div id="register_error" class="err" style="margin-top:8px"></div>
	  </form>
	</div>
  </div>

  <!-- Login Modal -->
  <div id="modal_login" class="modal">
	<div class="modal-content">
	  <button class="close" type="button" id="close_login">&times;</button>
	  <h2>Anmelden</h2>
	  <form id="form_login" autocomplete="off">
		<input type="email" name="email" id="login_email" placeholder="E-Mail" required value=""><br>
		<input type="password" name="password" id="login_pw" placeholder="Passwort" required value=""><br>

		<button class="btn" type="submit">Anmelden</button>
		<div id="login_error" class="err" style="margin-top:8px"></div>

		<div style="margin-top:10px; display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
		  <button class="btn" type="button" id="btn_pw_forgot" style="background:#eee;color:#333;font-weight:800;">
			Passwort vergessen?
		  </button>
		  <span id="pw_reset_msg" class="small"></span>
		</div>
		<div id="pw_reset_dev" class="small" style="margin-top:8px; word-break:break-all;"></div>
	  </form>
	</div>
  </div>

  <!-- Profile Modal -->
  <div id="modal_profile" class="modal">
	<div class="modal-content">
	  <button class="close" type="button" id="close_profile">&times;</button>
	  <h2>Profil</h2>

	  <div class="small" style="margin-bottom:10px;">
		Hier können Kundendaten gespeichert werden.
	  </div>

	  <form id="form_profile" autocomplete="off">
		<div class="row">
		  <div><label>Vorname</label><input type="text" name="first_name" required value=""></div>
		  <div><label>Nachname</label><input type="text" name="last_name" required value=""></div>
		</div>
		<div style="height:10px"></div>
		<div class="row">
		  <div><label>Firma (optional)</label><input type="text" name="company" value=""></div>
		  <div><label>Telefon (optional)</label><input type="tel" name="phone" value=""></div>
		</div>
		<div style="height:10px"></div>

		<label>E-Mail (nur Anzeige)</label>
		<input type="email" id="profile_email" disabled value="">
		<div style="height:10px"></div>

		<div class="row">
		  <div><label>Straße / Nr.</label><input type="text" name="street" required value=""></div>
		  <div><label>PLZ</label><input type="text" name="zip" required value=""></div>
		</div>
		<div style="height:10px"></div>

		<!-- ✅ HIER: Land mit Flag-Icons (DE default) -->
		<div class="row">
		  <div><label>Ort</label><input type="text" name="city" required value=""></div>
		  <div>
			<label>Land</label>
			<select name="country" required>
			  <option value="DE" selected>🇩🇪 DE</option>
			  <option value="AT">🇦🇹 AT</option>
			  <option value="CH">🇨🇭 CH</option>
			  <option value="NL">🇳🇱 NL</option>
			  <option value="BE">🇧🇪 BE</option>
			  <option value="LU">🇱🇺 LU</option>
			  <option value="FR">🇫🇷 FR</option>
			  <option value="IT">🇮🇹 IT</option>
			  <option value="DK">🇩🇰 DK</option>
			  <option value="SE">🇸🇪 SE</option>
			  <option value="NO">🇳🇴 NO</option>
			  <option value="PL">🇵🇱 PL</option>
			  <option value="CZ">🇨🇿 CZ</option>
			</select>
		  </div>
		</div>

		<div style="height:12px"></div>
		<button class="btn" type="submit" style="width:100%;">Profil speichern</button>
		<div id="profile_msg" class="small" style="margin-top:8px;"></div>

		<div style="height:14px"></div>
		<div class="warnbox" style="border-color:rgba(149,191,32,.45); background:rgba(149,191,32,.06);">
		  <div style="font-weight:900; margin-bottom:6px;">Passwort zurücksetzen</div>
		  <div class="small" style="margin-bottom:10px;">
			Erzeugt einen Reset-Link für diese E-Mail. Falls Mailversand nicht läuft, wird ein DEV-Link angezeigt.
		  </div>
		  <button class="btn" type="button" id="btn_profile_reset" style="width:100%; height:44px; font-weight:900;">
			Reset-Link senden
		  </button>
		  <div id="profile_reset_msg" class="small" style="margin-top:8px;"></div>
		  <div id="profile_reset_dev" class="small" style="margin-top:8px; word-break:break-all;"></div>
		</div>
	  </form>
	</div>
  </div>

  <script>
	function showModal(id){ const el=document.getElementById(id); if(el) el.style.display='flex'; }
	function hideModal(id){ const el=document.getElementById(id); if(el) el.style.display='none'; }

	// Buttons
	document.getElementById('btn_register').onclick = ()=> { clearRegisterForm(); showModal('modal_register'); };
	document.getElementById('btn_login').onclick    = ()=> { clearLoginForm(); showModal('modal_login'); };
	document.getElementById('close_register').onclick = ()=> hideModal('modal_register');
	document.getElementById('close_login').onclick    = ()=> hideModal('modal_login');
	document.getElementById('close_profile').onclick  = ()=> hideModal('modal_profile');

	// ✅ Formularfelder in Login/Register IMMER leeren (niemals prefilling)
	function clearRegisterForm(){
	  const f = document.getElementById('form_register');
	  if(!f) return;
	  f.reset();
	  ['reg_email','reg_email_confirm','reg_pw','reg_pw2'].forEach(id=>{
		const el=document.getElementById(id); if(el) el.value='';
	  });
	  const err = document.getElementById('register_error'); if(err) err.textContent='';
	}
	function clearLoginForm(){
	  const f = document.getElementById('form_login');
	  if(!f) return;
	  f.reset();
	  const e=document.getElementById('login_email'); if(e) e.value='';
	  const p=document.getElementById('login_pw'); if(p) p.value='';
	  const err = document.getElementById('login_error'); if(err) err.textContent='';
	  const m=document.getElementById('pw_reset_msg'); if(m) m.textContent='';
	  const d=document.getElementById('pw_reset_dev'); if(d) d.textContent='';
	}

	// ✅ Reset-Text: Spam-Hinweis immer ergänzen
	function withSpamHint(text){
	  const base = (text && String(text).trim()) ? String(text).trim() : 'Wenn die E-Mail existiert, wurde ein Reset-Link versendet.';
	  const add  = ' Bitte schauen Sie auch in Ihren Spam Ordner.';
	  if (base.toLowerCase().includes('spam')) return base;
	  return base + add;
	}

	// ✅ Country normalisieren (für Select mit Flag-Icons)
	function normCountryCode(v){
	  v = String(v || '').trim().toUpperCase();
	  const m = v.match(/\b([A-Z]{2})\b/);
	  if (m) return m[1];
	  return (v.slice(0,2) || 'DE');
	}

	// ✅ Checkout-Felder aus Userdaten befüllen
	function fillCheckoutFromUser(user, force=false){
	  const form = document.getElementById('pv_buy_form');
	  if(!form || !user) return;

	  function setIf(name, val){
		const el = form.querySelector('[name="'+name+'"]');
		if(!el) return;
		const cur = String(el.value || '').trim();
		const next = String(val || '').trim();
		if (!next) return;
		if (force || cur === '') el.value = next;
	  }

	  // Hauptfelder
	  setIf('first_name', user.first_name);
	  setIf('last_name',  user.last_name);
	  setIf('company',    user.company);
	  setIf('phone',      user.phone);
	  setIf('email',      user.email);
	  setIf('street',     user.street);
	  setIf('zip',        user.zip);
	  setIf('city',       user.city);

	  // ✅ FIX: Country immer als 2-Buchstaben-Code setzen (DE/AT/…)
	  setIf('country', normCountryCode(user.country || 'DE'));

	  // Optional: Angebot-Email mitfüllen, wenn leer
	  const offerEmail = document.getElementById('pv_offer_email');
	  if (offerEmail) {
		const cur = String(offerEmail.value || '').trim();
		const next = String(user.email || '').trim();
		if (next && (force || cur === '')) offerEmail.value = next;
	  }
	}

	// Auth UI
	const badge = document.getElementById('pv_user_badge');
	const btnProfile = document.getElementById('btn_profile');
	const btnLogout  = document.getElementById('btn_logout');
	const authHint   = document.getElementById('pv_auth_hint');

	let CURRENT_USER = null;

	async function api(params){
	  const res = await fetch('kunden_api.php', {
		method:'POST',
		headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
		body: new URLSearchParams(params)
	  });
	  const data = await res.json().catch(()=>null);
	  return {res, data};
	}

	async function refreshWhoami(){
	  const {res, data} = await api({action:'whoami'});
	  if(!res.ok || !data || !data.ok){
		CURRENT_USER = null;
		renderAuthUI();
		return;
	  }
	  CURRENT_USER = data.user || null;
	  renderAuthUI();
	  // ✅ beim Laden / Refresh: befüllen (nur leere Felder)
	  fillCheckoutFromUser(CURRENT_USER, false);
	}

	function renderAuthUI(){
	  const loggedIn = !!(CURRENT_USER && CURRENT_USER.email);
	  window.PV_IS_LOGGED_IN = loggedIn;

	  if(loggedIn){
		if(badge){ badge.style.display='inline-block'; badge.textContent = 'Angemeldet: ' + CURRENT_USER.email; }
		if(btnProfile) btnProfile.style.display='inline-flex';
		if(btnLogout)  btnLogout.style.display='inline-flex';
		if(authHint) authHint.textContent = 'Checkout-Felder wurden aus Ihrem Profil übernommen (bitte prüfen).';
	  }else{
		if(badge) badge.style.display='none';
		if(btnProfile) btnProfile.style.display='none';
		if(btnLogout)  btnLogout.style.display='none';
		if(authHint) authHint.textContent = 'Oder als Gast bestellen.';
	  }
	}

	// Logout
	btnLogout?.addEventListener('click', async ()=>{
	  await api({action:'logout'});
	  CURRENT_USER = null;
	  renderAuthUI();
	  alert('Abgemeldet.');
	});

	// Profile open + load data
	btnProfile?.addEventListener('click', async ()=>{
	  await refreshWhoami();
	  if(!CURRENT_USER){ alert('Nicht angemeldet.'); return; }

	  const f = document.getElementById('form_profile');
	  if(f){
		f.querySelector('input[name="first_name"]').value = (CURRENT_USER.first_name || '');
		f.querySelector('input[name="last_name"]').value  = (CURRENT_USER.last_name || '');
		f.querySelector('input[name="company"]').value    = (CURRENT_USER.company || '');
		f.querySelector('input[name="phone"]').value      = (CURRENT_USER.phone || '');
		f.querySelector('input[name="street"]').value     = (CURRENT_USER.street || '');
		f.querySelector('input[name="zip"]').value        = (CURRENT_USER.zip || '');
		f.querySelector('input[name="city"]').value       = (CURRENT_USER.city || '');

		// ✅ FIX: Country-Select korrekt setzen (DE/AT/…; auch wenn "🇩🇪 DE" gespeichert wäre)
		const cEl = f.querySelector('[name="country"]');
		if (cEl) cEl.value = normCountryCode(CURRENT_USER.country || 'DE');
	  }
	  const pe = document.getElementById('profile_email');
	  if(pe) pe.value = CURRENT_USER.email || '';

	  const pm = document.getElementById('profile_msg'); if(pm) pm.textContent='';
	  const prm = document.getElementById('profile_reset_msg'); if(prm) prm.textContent='';
	  const prd = document.getElementById('profile_reset_dev'); if(prd) prd.textContent='';

	  showModal('modal_profile');
	});

	// Register submit
	document.getElementById('form_register').addEventListener('submit', async (e)=>{
	  e.preventDefault();
	  const errEl = document.getElementById('register_error');
	  if(errEl) errEl.textContent='';

	  const email1 = (document.getElementById('reg_email')?.value || '').trim();
	  const email2 = (document.getElementById('reg_email_confirm')?.value || '').trim();
	  if (!email1 || !email2 || email1.toLowerCase() !== email2.toLowerCase()) {
		if (errEl) errEl.textContent = 'E-Mail stimmt nicht überein. Bitte zweimal identisch eingeben.';
		return;
	  }

	  const pw1 = (document.getElementById('reg_pw')?.value || '');
	  const pw2 = (document.getElementById('reg_pw2')?.value || '');
	  if (!pw1 || !pw2 || pw1 !== pw2) {
		if (errEl) errEl.textContent = 'Passwort stimmt nicht überein.';
		return;
	  }

	  const f = document.getElementById('form_register');
	  const fd = new FormData(f);

	  const params = new URLSearchParams();
	  params.set('action','register');
	  for (const [k,v] of fd.entries()) {
		if (k === 'email_confirm' || k === 'password2') continue;
		params.set(k, String(v ?? ''));
	  }

	  const {res, data} = await api(Object.fromEntries(params.entries()));

	  if(!res.ok || !data || !data.ok){
		if(errEl) errEl.textContent = (data && data.error) ? data.error : ('Fehler (HTTP '+res.status+')');
		return;
	  }

	  hideModal('modal_register');
	  await refreshWhoami();
	  // ✅ nach Registrierung: Felder direkt befüllen (force)
	  fillCheckoutFromUser(CURRENT_USER, true);
	  alert('Registrierung erfolgreich. Checkout-Felder wurden befüllt (bitte prüfen).');
	});

	// Login submit
	document.getElementById('form_login').addEventListener('submit', async (e)=>{
	  e.preventDefault();
	  const errEl = document.getElementById('login_error');
	  if(errEl) errEl.textContent='';

	  const email = (document.getElementById('login_email')?.value || '').trim();
	  const pw    = (document.getElementById('login_pw')?.value || '');

	  const {res, data} = await api({action:'login', email:email, password:pw});

	  if(!res.ok || !data || !data.ok){
		if(errEl) errEl.textContent = (data && data.error) ? data.error : ('Fehler (HTTP '+res.status+')');
		return;
	  }

	  hideModal('modal_login');
	  await refreshWhoami();
	  // ✅ nach Login: Felder direkt befüllen (force)
	  fillCheckoutFromUser(CURRENT_USER, true);
	  alert('Login erfolgreich. Checkout-Felder wurden befüllt (bitte prüfen).');
	});

	// Passwort Reset Request (Login Modal)
	document.getElementById('btn_pw_forgot')?.addEventListener('click', async ()=>{
	  const msg = document.getElementById('pw_reset_msg');
	  const dev = document.getElementById('pw_reset_dev');
	  if (msg) msg.textContent = '';
	  if (dev) dev.textContent = '';

	  const email = (document.getElementById('login_email')?.value || '').trim();
	  if(!email){
		if(msg) msg.textContent = 'Bitte E-Mail ins Login-Feld eintragen.';
		return;
	  }

	  const {res, data} = await api({action:'reset_request', email:email});

	  const base = (data && data.message) ? data.message : 'Wenn die E-Mail existiert, wurde ein Reset-Link versendet.';
	  if (msg) msg.textContent = withSpamHint(base);

	  if(data && data.dev_reset_link){
		if(dev) dev.innerHTML = 'DEV-Reset-Link: <a href="'+data.dev_reset_link+'">'+data.dev_reset_link+'</a>';
	  }
	});

	// Profil speichern
	document.getElementById('form_profile')?.addEventListener('submit', async (e)=>{
	  e.preventDefault();
	  const msg = document.getElementById('profile_msg');
	  if(msg){ msg.textContent=''; msg.className='small'; }

	  const f = document.getElementById('form_profile');
	  const fd = new FormData(f);

	  const params = { action:'update_profile' };
	  for(const [k,v] of fd.entries()) params[k] = String(v ?? '');

	  // ✅ FIX: immer als 2-Buchstaben-Code speichern
	  params.country = normCountryCode(params.country || 'DE');

	  const {res, data} = await api(params);

	  if(!res.ok || !data || !data.ok){
		if(msg){ msg.textContent = (data && data.error) ? data.error : ('Fehler (HTTP '+res.status+')'); msg.classList.add('err'); }
		return;
	  }

	  if(msg){ msg.textContent = 'Profil gespeichert.'; msg.classList.add('ok'); }
	  await refreshWhoami();
	  // ✅ nach Profil-Update: Checkout-Felder nachziehen (nur leere, nicht überschreiben)
	  fillCheckoutFromUser(CURRENT_USER, false);
	});

	// Reset-Link aus Profil
	document.getElementById('btn_profile_reset')?.addEventListener('click', async ()=>{
	  const msg = document.getElementById('profile_reset_msg');
	  const dev = document.getElementById('profile_reset_dev');
	  if(msg){ msg.textContent=''; msg.className='small'; }
	  if(dev) dev.textContent='';

	  await refreshWhoami();
	  const email = (CURRENT_USER && CURRENT_USER.email) ? CURRENT_USER.email : '';
	  if(!email){
		if(msg){ msg.textContent='Nicht angemeldet.'; msg.classList.add('err'); }
		return;
	  }

	  const {res, data} = await api({action:'reset_request', email:email});

	  if(!res.ok || !data){
		if(msg){ msg.textContent='Fehler beim Reset.'; msg.classList.add('err'); }
		return;
	  }

	  const base = (data && data.message) ? data.message : 'Wenn die E-Mail existiert, wurde ein Reset-Link versendet.';
	  if(msg){ msg.textContent = withSpamHint(base); msg.classList.add('ok'); }

	  if(data.dev_reset_link){
		if(dev) dev.innerHTML = 'DEV-Reset-Link: <a href="'+data.dev_reset_link+'">'+data.dev_reset_link+'</a>';
	  }
	});

	// Init
	refreshWhoami();
  </script>

  <script>
 /* =========================
	FREIGHT (RECHNUNG ODER LIEFERUNG) - FIX "NULLER"
	========================= */
 (function(){
   const form = document.getElementById('pv_buy_form');
   if (!form) return;

   const outB = document.getElementById('freight_brutto');
   const outN = document.getElementById('freight_netto');
   const outM = document.getElementById('freight_meta');
   const freightWrap = document.getElementById('freight_wrap');
   if (!outB || !outN || !outM) return;

   // Rechnungsadresse
   const billZipEl     = form.querySelector('input[name="zip"]');
   const billCountryEl = form.querySelector('select[name="country"], input[name="country"]');

   // Alt-Lieferadresse
   const altToggleEl   = document.getElementById('pv_ship_alt_toggle');
   const shipZipEl     = form.querySelector('input[name="ship_zip"]');
   const shipCountryEl = form.querySelector('select[name="ship_country"], input[name="ship_country"]');

   if (!billZipEl) { outM.textContent = 'PLZ-Feld nicht gefunden.'; return; }

   // Netto kleiner
   outN.style.fontSize = '80%';
   outN.style.lineHeight = '1.2';
   outN.style.marginTop = '0';

   function formatEUR(n){
	 n = Number(n);
	 if (!isFinite(n)) n = 0;
	 return n.toFixed(2).replace('.', ',') + ' €';
   }

   function parseMoney(txt){
	 txt = String(txt || '').trim();
	 if (!txt) return 0;
	 let s = txt.replace(/[^\d,.\-]/g, '');
	 if (s.includes(',') && s.includes('.')) s = s.replace(/\./g, '').replace(',', '.');
	 else s = s.replace(',', '.');
	 const n = parseFloat(s);
	 return isFinite(n) ? n : 0;
   }

   // ✅ FIX: Beim Tippen NICHT padStart → keine "Nuller"
   function sanitizeZip(zip, country, finalize){
	 zip = String(zip || '').trim();
	 country = String(country || 'DE').trim().toUpperCase();

	 if (country === 'DE') {
	   const d = (zip.replace(/\D/g,'') || '').slice(0,5);
	   if (!d) return '';
	   return finalize ? d.padStart(5,'0') : d;
	 }
	 return zip;
   }

   function isPickupSelected(){
	 return document.querySelector('input[name="payment"]:checked')?.value === 'cash_pickup';
   }

   function getReceiver(){
	 const useAlt = !!(altToggleEl && altToggleEl.checked);
	 const altZip = String(shipZipEl?.value || '').trim();

	 if (useAlt && altZip) {
	   return {
		 source: 'ship',
		 zipEl: shipZipEl,
		 countryEl: shipCountryEl,
		 zip: altZip,
		 country: String(shipCountryEl?.value || 'DE').trim().toUpperCase()
	   };
	 }

	 return {
	   source: 'bill',
	   zipEl: billZipEl,
	   countryEl: billCountryEl,
	   zip: String(billZipEl?.value || '').trim(),
	   country: String(billCountryEl?.value || 'DE').trim().toUpperCase()
	 };
   }

   let PV_PICKUP = false;

   let PV_FREIGHT_GROSS = 0;
   let PV_FREIGHT_NET   = 0;
   let PV_FREIGHT_ZONE  = '';
   let PV_FREIGHT_META  = '';
   let PV_FREIGHT_FOUND = false;

   const hidGross = document.getElementById('pv_freight_gross');
   const hidNet   = document.getElementById('pv_freight_net');
   const hidZone  = document.getElementById('pv_freight_zone');

   const hidOfferGross = document.getElementById('pv_offer_freight_gross');
   const hidOfferNet   = document.getElementById('pv_offer_freight_net');
   const hidOfferZone  = document.getElementById('pv_offer_freight_zone');

   function writeHidden(){
	 const g = (PV_FREIGHT_FOUND && !PV_PICKUP) ? PV_FREIGHT_GROSS : 0;
	 const n = (PV_FREIGHT_FOUND && !PV_PICKUP) ? PV_FREIGHT_NET   : 0;
	 const z = (PV_FREIGHT_FOUND && !PV_PICKUP) ? (PV_FREIGHT_ZONE || '') : '';

	 if (hidGross) hidGross.value = String(g);
	 if (hidNet)   hidNet.value   = String(n);
	 if (hidZone)  hidZone.value  = z;

	 if (hidOfferGross) hidOfferGross.value = String(g);
	 if (hidOfferNet)   hidOfferNet.value   = String(n);
	 if (hidOfferZone)  hidOfferZone.value  = z;
   }

   function renderFreightRow(){
	 const list = document.getElementById('pv_sum_list');
	 if (!list) return;

	 let row = document.getElementById('pv_freight_row');
	 if (!row) {
	   row = document.createElement('div');
	   row.id = 'pv_freight_row';
	   row.className = 'sum-row';
	   row.innerHTML = `
		 <div class="sum-left">
		   <div>
			 <div class="sum-title">Frachtkosten</div>
			 <div class="sum-meta" id="pv_freight_row_meta"></div>
		   </div>
		 </div>
		 <div class="sum-right">
		   <div id="pv_freight_row_gross">—</div>
		   <div class="net" id="pv_freight_row_net"></div>
		 </div>
	   `;
	   list.appendChild(row);
	 }

	 const active = PV_FREIGHT_FOUND && !PV_PICKUP;
	 row.style.display = active ? '' : 'none';
	 if (!active) return;

	 const g = document.getElementById('pv_freight_row_gross');
	 const n = document.getElementById('pv_freight_row_net');
	 const m = document.getElementById('pv_freight_row_meta');

	 if (g) g.textContent = formatEUR(PV_FREIGHT_GROSS) + ' brutto';
	 if (n) n.textContent = PV_FREIGHT_NET > 0 ? (formatEUR(PV_FREIGHT_NET) + ' ohne MwSt.') : '';
	 if (m) m.textContent = PV_FREIGHT_META || '';
   }

   function applyFreightToTotals(){
	 const sumGrossEl = document.getElementById('pv_sum_gross');
	 const sumNetEl   = document.getElementById('pv_sum_net');
	 if (!sumGrossEl) return;

	 const active = PV_FREIGHT_FOUND && !PV_PICKUP;
	 const freightG = active ? PV_FREIGHT_GROSS : 0;
	 const freightN = active ? PV_FREIGHT_NET   : 0;

	 const currentGross = parseMoney(sumGrossEl.textContent);
	 const alreadyG = Number(sumGrossEl.dataset.pvFreightGross || 0) || 0;
	 const baseGross = Math.max(0, currentGross - alreadyG);
	 sumGrossEl.dataset.pvFreightGross = String(freightG);
	 sumGrossEl.textContent = formatEUR(baseGross + freightG);

	 if (sumNetEl) {
	   const currentNet = parseMoney(sumNetEl.textContent);
	   const alreadyN = Number(sumNetEl.dataset.pvFreightNet || 0) || 0;
	   const baseNet = Math.max(0, currentNet - alreadyN);
	   sumNetEl.dataset.pvFreightNet = String(freightN);
	   const totalNet = baseNet + freightN;
	   sumNetEl.textContent = totalNet > 0 ? (formatEUR(totalNet) + ' ohne MwSt.') : '';
	 }
   }

   function applyAll(){
	 renderFreightRow();
	 applyFreightToTotals();
	 writeHidden();
   }

   function setPickupMode(on){
	 PV_PICKUP = !!on;
	 if (freightWrap) freightWrap.classList.toggle('is-pickup', PV_PICKUP);

	 if (PV_PICKUP) {
	   PV_FREIGHT_GROSS = 0;
	   PV_FREIGHT_NET   = 0;
	   PV_FREIGHT_ZONE  = '';
	   PV_FREIGHT_META  = '';
	   PV_FREIGHT_FOUND = false;

	   outM.textContent = 'Abholung gewählt – keine Frachtkosten.';
	   outB.textContent = '—';
	   outN.textContent = '';
	   applyAll();
	 }
   }

   let lastKey = '';
   let inFlight = false;

   async function fetchFreight(zip, country){
	 const url = 'ajax_freightcost.php?zip=' + encodeURIComponent(zip) + '&country=' + encodeURIComponent(country);
	 const res = await fetch(url, { cache: 'no-store' });
	 const data = await res.json().catch(()=>null);
	 if (!res.ok || !data || !data.ok) throw new Error((data && data.error) ? data.error : ('HTTP ' + res.status));
	 return data;
   }

   async function updateFreight(force=false){
	 if (PV_PICKUP) { applyAll(); return; }

	 const r = getReceiver();
	 const country = String(r.country || 'DE').trim().toUpperCase();

	 const rawZip = String(r.zip || '').trim();
	 const zipLive = sanitizeZip(rawZip, country, false);
	 const zipFinal = sanitizeZip(rawZip, country, true);

	 if (force && r.zipEl && zipFinal && zipFinal !== rawZip) r.zipEl.value = zipFinal;

	 if (country === 'DE' && zipLive.length > 0 && zipLive.length < 5) {
	   PV_FREIGHT_FOUND = false;
	   PV_FREIGHT_GROSS = 0;
	   PV_FREIGHT_NET   = 0;
	   PV_FREIGHT_ZONE  = '';
	   PV_FREIGHT_META  = '';

	   outM.textContent = 'Bitte 5-stellige PLZ eingeben.';
	   outB.textContent = '—';
	   outN.textContent = '';
	   applyAll();
	   return;
	 }

	 const zip = zipLive;
	 const key = r.source + '|' + country + '|' + zip;

	 if (!force && key === lastKey) return;
	 lastKey = key;

	 if (!zip) {
	   PV_FREIGHT_FOUND = false;
	   PV_FREIGHT_GROSS = 0;
	   PV_FREIGHT_NET   = 0;
	   PV_FREIGHT_ZONE  = '';
	   PV_FREIGHT_META  = '';

	   outM.textContent = 'Bitte geben Sie die PLZ des Empfängers ein.';
	   outB.textContent = '—';
	   outN.textContent = '';
	   applyAll();
	   return;
	 }

	 if (inFlight) return;
	 inFlight = true;

	 outM.textContent = 'Frachtkosten werden geladen … (' + country + ' ' + zip + ')';

	 try{
	   const data = await fetchFreight(zip, country);

	   if (!data.found) {
		 PV_FREIGHT_FOUND = false;
		 PV_FREIGHT_GROSS = 0;
		 PV_FREIGHT_NET   = 0;
		 PV_FREIGHT_ZONE  = '';
		 PV_FREIGHT_META  = '';

		 outM.textContent = 'Keine Frachtkosten gefunden für ' + country + ' ' + zip + '. Bitte PLZ prüfen.';
		 outB.textContent = '—';
		 outN.textContent = '';
		 applyAll();
		 return;
	   }

	   PV_FREIGHT_GROSS = Number(data.brutto_value || 0) || 0;
	   PV_FREIGHT_NET   = Number(data.netto_value  || 0) || 0;
	   PV_FREIGHT_ZONE  = String(data.zone || '');
	   PV_FREIGHT_META  = String(data.meta || '');
	   PV_FREIGHT_FOUND = true;

	   const metaPretty = PV_FREIGHT_META.replace(/\bfachbodenregal\b/g, 'Fachbodenregal');
	   PV_FREIGHT_META = metaPretty;

	   outM.textContent = metaPretty || 'Frachtkosten geladen.';
	   outB.textContent = (data.brutto ? String(data.brutto) : formatEUR(PV_FREIGHT_GROSS)) + ' brutto';
	   outN.textContent = (data.netto ? String(data.netto) : (formatEUR(PV_FREIGHT_NET) + ' ohne MwSt.'))
		 .replace(/\s*netto\b/i, ' ohne MwSt.');

	   applyAll();
	 }catch(e){
	   PV_FREIGHT_FOUND = false;
	   PV_FREIGHT_GROSS = 0;
	   PV_FREIGHT_NET   = 0;
	   PV_FREIGHT_ZONE  = '';
	   PV_FREIGHT_META  = '';

	   outM.textContent = 'Fehler beim Laden der Frachtkosten.';
	   outB.textContent = '—';
	   outN.textContent = '';
	   applyAll();
	 }finally{
	   inFlight = false;
	 }
   }

   let t=null;
   billZipEl.addEventListener('input', ()=>{ clearTimeout(t); t=setTimeout(()=>updateFreight(false), 250); });
   billZipEl.addEventListener('change', ()=>updateFreight(true));
   billZipEl.addEventListener('blur',  ()=>updateFreight(true));
   billCountryEl?.addEventListener('change', ()=>updateFreight(true));

   altToggleEl?.addEventListener('change', ()=>updateFreight(true));
   shipZipEl?.addEventListener('input', ()=>{ clearTimeout(t); t=setTimeout(()=>updateFreight(false), 250); });
   shipZipEl?.addEventListener('change', ()=>updateFreight(true));
   shipZipEl?.addEventListener('blur',  ()=>updateFreight(true));
   shipCountryEl?.addEventListener('change', ()=>updateFreight(true));

   document.addEventListener('change', (e)=>{
	 if (e.target && e.target.matches('input[name="payment"]')) {
	   setPickupMode(isPickupSelected());
	   if (!isPickupSelected()) updateFreight(true);
	 }
   });

   setInterval(()=>{ setPickupMode(isPickupSelected()); }, 400);
   setInterval(()=>{ updateFreight(false); }, 700);

   setPickupMode(isPickupSelected());
   updateFreight(true);
 })();
 </script>

<script>
 /* =========================
	FIX: Submit-Button darf NICHT wegen Alt-Lieferadresse deaktiviert werden
	- Button ist nur deaktiviert, wenn keine Bezahlmethode gewählt ist
	- PayPal: Button bleibt versteckt (wie in deiner PayPal-Logik)
	========================= */
 (function(){
   const form = document.getElementById('pv_buy_form');
   const btn  = document.getElementById('pv_buy_submit');
   if (!form || !btn) return;

   function selectedPayment(){
	 return document.querySelector('input[name="payment"]:checked')?.value || '';
   }

   function syncSubmit(){
	 const pay = selectedPayment();

	 if (!pay) { btn.disabled = true; return; }

	 if (pay === 'paypal') { btn.disabled = false; return; }

	 btn.disabled = false;
   }

   form.addEventListener('input',  syncSubmit, true);
   form.addEventListener('change', syncSubmit, true);

   document.getElementById('pv_ship_alt_toggle')?.addEventListener('change', syncSubmit);

   document.querySelectorAll('input[name="payment"]').forEach(el => {
	 el.addEventListener('change', syncSubmit);
   });

   setInterval(syncSubmit, 300);
   syncSubmit();
 })();
 </script>
<script>
 /* =========================
	ALT-LIEFERADRESSE TOGGLE (einmalig)
	========================= */
 (function(){
   const toggle = document.getElementById('pv_ship_alt_toggle');
   const box    = document.getElementById('pv_ship_alt_box');
   if (!toggle || !box) return;
 
   const fields = Array.from(box.querySelectorAll('input, select'));
 
   // welche Felder sollen Pflicht sein, wenn aktiviert?
   const requiredNames = new Set([
	 'ship_first_name','ship_last_name','ship_street','ship_zip','ship_city','ship_country'
   ]);
 
   function setEnabled(on){
	 box.style.display = on ? 'block' : 'none';
 
	 fields.forEach(el => {
	   el.disabled = !on;
 
	   const n = el.getAttribute('name') || '';
	   if (requiredNames.has(n)) el.required = !!on;
 
	   // optional: beim Ausschalten leeren
	   if (!on && el.tagName === 'INPUT')  el.value = '';
	   if (!on && el.tagName === 'SELECT') el.value = 'DE';
	 });
   }
 
   toggle.addEventListener('change', () => setEnabled(toggle.checked));
 
   // initial
   setEnabled(toggle.checked);
 })();
 </script>
<div id="footer"><p>REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull; Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
	   Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull; Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr  <br><a href="https://www.regatix.com/pages/start/impressum.php" target="_blank">Impressum</a> | <a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank">Datenschutz</a> | <a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank">Versandbedingungen</a> | <a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Wideruf</a> | <a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank">SHOP Bedingungen</a></p>
 </div>
</body>
</html>
