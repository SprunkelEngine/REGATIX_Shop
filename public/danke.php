<?php
declare(strict_types=1);

$isOffer = isset($_GET['offer']) && $_GET['offer'] === '1';
?>
<!doctype html>
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
  <title><?= $isOffer ? 'Angebotsanfrage gesendet' : 'Vielen Dank' ?></title>

  <!-- Shop CSS -->
  <link rel="stylesheet" href="assets/styles.css">

  <!-- Checkout-Layout-Styles (angepasst / übernommen) -->
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
	.card{ background: var(--card) !important; box-shadow: var(--shadow); border-radius:18px; border:1px solid var(--border); }
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

	.container{ max-width: 1100px; margin: 0 auto; padding: 18px 14px 40px; }
	.header{ display:flex; justify-content:space-between; align-items:flex-start; gap:14px; margin: 18px 0 14px; flex-wrap:wrap; }
	.h-title{ font-size: 28px; font-weight: 900; color: var(--text); line-height:1.1; }
	.h-sub{ margin-top:6px; color: var(--muted); }

	.top-userbar{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
	.card-h{ padding:16px 18px; border-bottom: 1px solid var(--border); }
	.card-title{ font-weight: 900; color: var(--text); }
	.card-b{ padding:18px; color: var(--text); }

	.infobox{
	  border:1.5px solid rgba(149,191,32,.55);
	  border-radius: 18px;
	  padding:14px;
	  background: rgba(149,191,32,.08);
	}
	body.layout-pro .infobox{
	  border-color: rgba(118,167,255,.45);
	  background: rgba(118,167,255,.08);
	}

	.small{ font-size:13px; color:var(--muted); }
	body.layout-pro .small{ color:rgba(232,240,255,.65); }

	.actions{ display:flex; gap:10px; flex-wrap:wrap; margin-top:14px; }
  </style>
</head>

<body>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-W5WRQLFN"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

  <!-- Logo (wie Checkout) -->
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
		<div class="h-title">
		  <?= $isOffer ? 'Angebot angefordert' : 'Vielen Dank' ?>
		</div>
		<div class="h-sub">
		  <?= $isOffer
			? 'Ihre Angebotsanfrage wurde übermittelt.'
			: 'Ihre Bestellung wurde übermittelt.' ?>
		</div>
	  </div>

	  <div class="top-userbar">
		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="index.php">Zum Shop</a>
		<a class="btn" href="cart.php">Warenkorb</a>
	  </div>
	</div>

	<div class="card">
	  <div class="card-h">
		<div class="card-title">
		  <?= $isOffer ? 'Angebotsanfrage gesendet' : 'Bestellung eingegangen' ?>
		</div>
	  </div>

	  <div class="card-b">
		<div class="infobox">
		  <?php if ($isOffer): ?>
			<div style="font-weight:900; font-size:18px; margin-bottom:6px;">
			  Wir erstellen Ihr individuelles Angebot
			</div>
			<div style="margin-bottom:10px;">
			  Ihre <strong>Angebotsanfrage</strong> wurde an den Betreiber gesendet.
			  Sie erhalten in Kürze ein individuelles Angebot per E-Mail.
			</div>
			<div class="small">
			  Hinweis: Es wurde <strong>keine Bestellung</strong> ausgelöst.
			</div>
		  <?php else: ?>
			<div style="font-weight:900; font-size:18px; margin-bottom:6px;">
			  Danke für Ihre Bestellung
			</div>
			<div style="margin-bottom:10px;">
			  Sie erhalten in Kürze eine Bestätigung/Proforma per E-Mail.
			</div>
			<div class="small">
			  Falls keine Mail ankommt: Bitte Spam-Ordner prüfen.
			</div>
		  <?php endif; ?>
		</div>

		<div class="actions">
		  <a class="btn" href="index.php">Weiter einkaufen</a>
		  <a class="btn" href="cart.php">Zum Warenkorb</a>
		</div>
	  </div>
	</div>

  </div>

  <!-- Layout Toggle wie Checkout (pv_layout) -->
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
  <div id="footer">
	  <p>
		REGATIX Betriebseinrichtungen GmbH &bull; Porschestra&szlig;e 9 &bull; 74360 Ilsfeld &bull; Telefon: 07062 - 23 902 - 0 &bull; E-Mail: info@regatix.com<br />
		Montag - Donnerstag: 08:00 - 12:00 Uhr | 13:00 - 17:00 Uhr &bull; Freitag:08:00 - 12:00 Uhr | 13:00 - 16:30 Uhr <br>
		<a href="https://www.regatix.com/pages/start/impressum.php" target="_blank">Impressum</a> |
		<a href="https://www.regatix.com/pages/start/datenschutz.php" target="_blank">Datenschutz</a> |
		<a href="https://www.regatix.com/pages/start/versandbedingungen.php" target="_blank">Versandbedingungen</a> |
		<a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Datenschutz</a> |
		<a href="https://www.regatix.com/pages/start/widerrufsrecht.php" target="_blank">Wideruf</a> |
		<a href="https://www.regatix.com/pages/start/shop-bedingungen.php" target="_blank">SHOP Bedingungen</a>
	  </p>
	</div>
</body>
</html>
