<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/OfferRepository.php';

use PV\Repository;
use PV\OfferRepository;

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];
$offersRepo = new OfferRepository(__DIR__ . '/../config/offers.json');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function money($v): string {
  if ($v === null || $v === '') return '';
  if (is_string($v) && !is_numeric($v)) return (string)$v; // falls schon formatiert
  $f = (float)$v;
  return number_format($f, 2, ',', '.') . ' €';
}

function num_or_null($v): ?float {
  if ($v === null) return null;
  if (is_float($v) || is_int($v)) return (float)$v;
  $s = trim((string)$v);
  if ($s === '') return null;
  // erlaubt "199,99" oder "199.99"
  $s = str_replace([' ', "\u{00A0}"], '', $s);
  $s = str_replace(',', '.', $s);
  if (!is_numeric($s)) return null;
  return (float)$s;
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '' || !preg_match('~^[A-Za-z0-9_\-]+$~', $id)) {
  http_response_code(400);
  echo "Bad Request";
  exit;
}

$offer = $offersRepo->get($id);
if (!$offer) {
  http_response_code(404);
  echo "Offer not found";
  exit;
}

/* ✅ Aktiv + Zeitraum prüfen */
if (empty($offer['active'])) {
  http_response_code(404);
  echo "Offer inactive";
  exit;
}
$today  = date('Y-m-d');
$starts = (string)($offer['starts_at'] ?? '');
$ends   = (string)($offer['ends_at'] ?? '');
if ($starts !== '' && $today < $starts) { http_response_code(404); echo "Offer not active yet"; exit; }
if ($ends   !== '' && $today > $ends)   { http_response_code(404); echo "Offer expired"; exit; }

/* ✅ iFrame erlauben (wichtig!) */
$frameAncestors = $config['security']['offer_frame_ancestors'] ?? '*'; // z.B. "'self' https://deine-domain.de"
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Kein X-Frame-Options setzen, sonst blockt der Browser Embeds
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors {$frameAncestors};");

$productKey = (string)($offer['product'] ?? '');
$article    = (string)($offer['article'] ?? '');
if ($productKey === '' || $article === '') {
  http_response_code(500);
  echo "Offer invalid";
  exit;
}

/* Produktdaten */
$repo   = new Repository($configPath, $productKey);
$cfg    = $repo->getConfig();
$detail = $repo->detail($article);
$art    = is_array($detail['article'] ?? null) ? $detail['article'] : [];

/* Content */
$title    = trim((string)($offer['title'] ?? ''));
$badge    = trim((string)($offer['badge'] ?? ''));
$subtitle = trim((string)($offer['subtitle'] ?? ''));

$ctaLabel = (string)($offer['cta_label'] ?? 'Jetzt konfigurieren');
$ctaUrl   = (string)($offer['cta_url'] ?? '');
if ($ctaUrl === '') {
  $ctaUrl = '/index.php?product=' . rawurlencode($productKey) . '&article=' . rawurlencode($article) . '&offer=' . rawurlencode($id);
}

/* Bild */
$img = null;
$imgs = $detail['content_article']['images'] ?? [];
if (is_array($imgs) && !empty($imgs[0])) $img = (string)$imgs[0];

/* Preise: vorher = Variante, nachher = Offer (oder fallback Variante) */
$priceGrossKey = (string)($cfg['variant']['price_gross'] ?? '');
$priceNetKey   = (string)($cfg['variant']['price_net'] ?? '');

$beforeGross = null;
$beforeNet   = null;

if ($priceGrossKey !== '' && isset($art[$priceGrossKey])) $beforeGross = num_or_null($art[$priceGrossKey]);
if ($priceNetKey   !== '' && isset($art[$priceNetKey]))   $beforeNet   = num_or_null($art[$priceNetKey]);

$afterGross = num_or_null($offer['price_gross'] ?? null);
$afterNet   = num_or_null($offer['price_net'] ?? null);

/* Falls Offer keinen Preis setzt: "nachher" bleibt Variante */
if ($afterGross === null) $afterGross = $beforeGross;
if ($afterNet   === null) $afterNet   = $beforeNet;

/* Anzeige-Logik */
$hasAfter = ($afterGross !== null || $afterNet !== null);
$hasBefore = ($beforeGross !== null || $beforeNet !== null);

/* Vorher/Nachher nur zeigen, wenn Offer wirklich anders ist (oder explizit gesetzt) */
$offerExplicit = (isset($offer['price_gross']) && $offer['price_gross'] !== null && $offer['price_gross'] !== '')
			  || (isset($offer['price_net'])   && $offer['price_net']   !== null && $offer['price_net']   !== '');

$showBeforeAfter = $offerExplicit && $hasAfter && $hasBefore &&
  (
	($beforeGross !== null && $afterGross !== null && abs($beforeGross - $afterGross) > 0.00001) ||
	($beforeNet   !== null && $afterNet   !== null && abs($beforeNet   - $afterNet)   > 0.00001)
  );

/* Hauptpreis fürs große Label */
$mainAfter = ($afterGross !== null) ? money($afterGross) : (($afterNet !== null) ? money($afterNet) : '');
$mainBefore = ($beforeGross !== null) ? money($beforeGross) : (($beforeNet !== null) ? money($beforeNet) : '');

/* Datum-Label */
$periodLabel = '';
if ($starts !== '' || $ends !== '') {
  $periodLabel = trim(($starts !== '' ? $starts : '—') . ' bis ' . ($ends !== '' ? $ends : '—'));
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title !== '' ? $title : 'Angebot') ?></title>

  <style>
	:root{
	  --bg:#ffffff;
	  --card:#ffffff;
	  --text:#0f172a;
	  --muted:#475569;
  	  --accent:#2563eb;
	  --danger:#ef4444;
	}

	*{ box-sizing:border-box; }
	body{
	  margin:0;
	  font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;
	  color:var(--text);
 
	}

	/* ✅ kompakte Kachel */
	.wrap{ padding:10px; display:flex; justify-content:center; }
	.card{
	  width:100%;
	  max-width:320px;
	  background: var(--card);
	  border:1px solid var(--border);
	  border-radius:16px;
	  box-shadow: var(--shadow);
	  overflow:hidden;
	  float: left
	}

	.top{ padding:12px 12px 0 12px; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
	.badge{
	  display:inline-block;
	  padding:5px 9px;
	  border-radius:999px;
	  border:1px solid var(--border);
	  font-size:11px;
	  color:var(--muted);
	  background: rgba(15,23,42,.03);
	}
	.title{ font-size:16px; font-weight:850; margin:0; line-height:1.2; }

	.img{
	  margin:12px;
	  border-radius:14px;
	  border:1px solid var(--border);
	  overflow:hidden;
	  background: rgba(15,23,42,.04);
	  min-height:180px;
	  display:flex; align-items:center; justify-content:center;
	}
	.img img{ width:100%; height:100%; object-fit:cover; display:block; }

	/* 1) Angebotstext unter Bild */
	.subwrap{ padding:0 12px 10px 12px; }
	.sub{
	  color:var(--muted);
	  font-size:13px;
	  margin:0;
	  line-height:1.35;
	  white-space:pre-wrap;
	}

	/* 2) Preisfeld unter Angebotstext */
	.box{
	  margin:0 12px 12px 12px;
	  border:1px solid var(--border);
	  border-radius:14px;
	  padding:12px;
	  background: rgba(15,23,42,.02);
	}

	.price-row{ display:flex; flex-direction:column; gap:8px; }

	.old-wrap{
	  position:relative;
	  display:inline-block;
	  padding:5px 9px;
	  border-radius:12px;
	  border:1px dashed rgba(239,68,68,.45);
	  background: rgba(239,68,68,.06);
	  align-self:flex-start;
	}
	.old-price{
	  font-size:18px;
	  font-weight:900;
	  color: rgba(15,23,42,.65);
	  text-decoration: line-through;
	  text-decoration-thickness: 3px;
	  text-decoration-color: rgba(239,68,68,.85);
	}
	.old-wrap::before,
	.old-wrap::after{
	  content:"";
	  position:absolute;
	  left:7px; right:7px;
	  top:50%;
	  height:3px;
	  background: rgba(239,68,68,.90);
	  border-radius:999px;
	  transform-origin:center;
	  pointer-events:none;
	  box-shadow: 0 2px 10px rgba(239,68,68,.25);
	}
	.old-wrap::before{ transform: translateY(-50%) rotate(18deg); }
	.old-wrap::after{  transform: translateY(-50%) rotate(-18deg); }

	.new-price{
	  font-size:24px;
	  font-weight:950;
	  margin:0;
	  letter-spacing:-.01em;
	  line-height:1.1;
	}
	.muted{ color:var(--muted); font-size:12px; margin-top:2px; line-height:1.35; }

	.btn{
	  display:flex; align-items:center; justify-content:center;
	  height:42px; padding:0 14px;
	  border-radius:12px;
	  border:1px solid rgba(37,99,235,.35);
	  background: linear-gradient(135deg, rgba(37,99,235,.14), rgba(15,23,42,.02));
	  color:var(--text);
	  text-decoration:none;
	  font-weight:850;
	  margin-top:10px;
	}
	.btn:hover{ border-color: rgba(37,99,235,.55); }

	.meta{
	  margin-top:10px;
	  padding-top:10px;
	  border-top:1px solid var(--border);
	  color:var(--muted);
	  font-size:12px;
	  line-height:1.45;
	}
  </style>
</head>
<body>
  <div class="wrap">
	<div class="card">

	  <div class="top">
		<?php if ($badge !== ''): ?><span class="badge"><?= h($badge) ?></span><?php endif; ?>
		<h1 class="title"><?= h($title !== '' ? $title : ($productKey . ' · ' . $article)) ?></h1>
	  </div>

	  <div class="img">
		<?php if ($img): ?>
		  <img src="../images/<?= h($img) ?>" alt="">
		<?php else: ?>
		  <div class="muted">Kein Bild hinterlegt</div>
		<?php endif; ?>
	  </div>

	  <?php if ($subtitle !== ''): ?>
		<div class="subwrap">
		  <p class="sub"><?= h($subtitle) ?></p>
		</div>
	  <?php endif; ?>

	  <div class="box">
		<div class="price-row">
		  <?php if ($hasAfter): ?>
			<?php if ($showBeforeAfter && $mainBefore !== ''): ?>
			  <div class="old-wrap" aria-label="Vorher-Preis">
				<div class="old-price"><?= h($mainBefore) ?></div>
			  </div>
			<?php endif; ?>

			<p class="new-price"><?= h($mainAfter !== '' ? $mainAfter : 'Preis auf Anfrage') ?></p>

			<?php if ($afterGross !== null && $afterNet !== null): ?>
			  <div class="muted">Netto: <?= h(money($afterNet)) ?></div>
			<?php endif; ?>
		  <?php else: ?>
			<p class="new-price">Preis auf Anfrage</p>
		  <?php endif; ?>
		</div>

		<a class="btn" href="<?= h($ctaUrl) ?>" target="_top" rel="noopener">
		  <?= h($ctaLabel) ?>
		</a>

		<div class="meta">
		  Produkt: <strong><?= h($productKey) ?></strong><br>
		  Variante: <strong><?= h($article) ?></strong><br><br>
		  Es fallen eventuell noch Frachtkosten an.
		  <?php if ($periodLabel !== ''): ?>
			<br><br>Gültig: <strong><?= h($periodLabel) ?></strong>
		  <?php endif; ?>
		</div>
	  </div>

	  <!-- 3) Footer/ID komplett entfernt -->

	</div>
  </div>
</body>
</html>