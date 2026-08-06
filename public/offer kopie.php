<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/OfferRepository.php';

use PV\Repository;
use PV\OfferRepository;

/* ------------------------------
   Config / Repos
-------------------------------- */
$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];
$offersRepo = new OfferRepository(__DIR__ . '/../config/offers.json');

/* ------------------------------
   Helpers
-------------------------------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Robust parsing:
 * - accepts: 1.234,56 | 1234,56 | 1234.56 | "1 234,56 €" | "EUR 123,45" | "199,00 €*"
 */
function num_or_null($v): ?float {
  if ($v === null) return null;
  if (is_float($v) || is_int($v)) return (float)$v;

  $s = trim((string)$v);
  if ($s === '') return null;

  $s = str_replace(["\u{00A0}", ' '], '', $s);
  $s = str_replace('€', '', $s);
  $s = preg_replace('~[^0-9,\.\-]~', '', $s);
  if ($s === '' || $s === '-') return null;

  // if both "." and "," exist: treat "." as thousands and "," as decimal
  if (str_contains($s, '.') && str_contains($s, ',')) {
	$s = str_replace('.', '', $s);
	$s = str_replace(',', '.', $s);
  } else {
	// if only "," exists -> decimal
	if (str_contains($s, ',')) $s = str_replace(',', '.', $s);
  }

  if (!is_numeric($s)) return null;
  return (float)$s;
}

function money(?float $v): string {
  if ($v === null) return '';
  return number_format($v, 2, ',', '.') . ' €';
}

/** Build CTA cart url consistently */
function build_cart_url(string $base, string $product, string $article, string $offerId, ?float $gross, ?float $net): string {
  $params = [
	'product' => $product,
	'article' => $article,
	'offer'   => $offerId,
	'qty'     => '1',
  ];
  if ($gross !== null) $params['price_gross_unit'] = (string)$gross;
  if ($net   !== null) $params['price_net_unit']   = (string)$net;

  return rtrim($base, '/') . '/public/cart.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/* ------------------------------
   Input / Offer
-------------------------------- */
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

/* ✅ Embed-Modus erkennen (z.B. offer.php?id=...&embed=1) */
$isEmbed = ((string)($_GET['embed'] ?? '0') === '1');

/* ✅ Pro Angebot: soll der Listenpreis (Old/Vorher) im iFrame gezeigt werden? */
$embedShowListPrice = !empty($offer['embed_show_list_price']); // boolean in offers.json

/* ✅ In Public URL immer zeigen; im Embed nur, wenn Flag an ist */
$allowOldPriceInThisView = (!$isEmbed) || $embedShowListPrice;

/* ✅ Format/Orientierung (NUR Layout, sonst nichts verändern)
   - auto (default): Portrait = wie bisher; Landscape = Querformat-Layout
   - portrait: immer wie bisher
   - landscape: immer Querformat-Layout
   Optional: offer.php?id=...&embed=1&layout=landscape  oder &layout=portrait
*/
$layout = strtolower(trim((string)($_GET['layout'] ?? 'auto')));
if (!in_array($layout, ['auto','portrait','landscape'], true)) $layout = 'auto';

/* Active + period check */
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

/* Security headers (iframe allowed via frame-ancestors) */
$frameAncestors = $config['security']['offer_frame_ancestors'] ?? '*';
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-ancestors {$frameAncestors};");

/* Required offer fields */
$productKey = (string)($offer['product'] ?? '');
$article    = (string)($offer['article'] ?? '');
if ($productKey === '' || $article === '') {
  http_response_code(500);
  echo "Offer invalid";
  exit;
}

/* ------------------------------
   Product data
-------------------------------- */
$repo   = new Repository($configPath, $productKey);
$cfg    = $repo->getConfig();
$detail = $repo->detail($article);
$art    = is_array($detail['article'] ?? null) ? $detail['article'] : [];

/* Content */
$title    = trim((string)($offer['title'] ?? ''));
$badge    = trim((string)($offer['badge'] ?? ''));
$subtitle = trim((string)($offer['subtitle'] ?? ''));

/* CTA */
$ctaLabel = (string)($offer['cta_label'] ?? 'In den Warenkorb');
$ctaUrl   = (string)($offer['cta_url'] ?? '');

/* Offer prices (raw) */
$offerGross = num_or_null($offer['price_gross'] ?? null);
$offerNet   = num_or_null($offer['price_net'] ?? null);

/* If cta_url is empty or points to index.php, build to cart.php */
if ($ctaUrl === '' || preg_match('~(^|/)index\.php~i', $ctaUrl)) {
  $ctaUrl = build_cart_url(pv_base_url(), $productKey, $article, $id, $offerGross, $offerNet);
}

/* Image */
$img = null;
$imgs = $detail['content_article']['images'] ?? [];
if (is_array($imgs) && !empty($imgs[0])) $img = (string)$imgs[0];

/* ------------------------------
   Price logic (optimized + robust)
-------------------------------- */

/**
 * BEFORE price:
 * Prefer stored "before_price_*" from the offer (admin wrote it),
 * fallback to variant prices from product detail.
 */
$beforeGross = num_or_null($offer['before_price_gross'] ?? null);
$beforeNet   = num_or_null($offer['before_price_net'] ?? null);

$priceGrossKey = (string)($cfg['variant']['price_gross'] ?? '');
$priceNetKey   = (string)($cfg['variant']['price_net'] ?? '');

if ($beforeGross === null && $priceGrossKey !== '' && array_key_exists($priceGrossKey, $art)) {
  $beforeGross = num_or_null($art[$priceGrossKey]);
}
if ($beforeNet === null && $priceNetKey !== '' && array_key_exists($priceNetKey, $art)) {
  $beforeNet = num_or_null($art[$priceNetKey]);
}

/**
 * AFTER price:
 * Offer price preferred, fallback to BEFORE (variant) if offer missing.
 */
$afterGross = $offerGross ?? $beforeGross;
$afterNet   = $offerNet   ?? $beforeNet;

$hasAfter  = ($afterGross !== null || $afterNet !== null);
$hasBefore = ($beforeGross !== null || $beforeNet !== null);

/**
 * Always show the original price (rot + durchgestrichen) when it exists.
 * ✅ ABER: im iFrame nur, wenn pro Angebot aktiviert.
 */
$mainBefore = (($beforeGross !== null) ? money($beforeGross) : (($beforeNet !== null) ? money($beforeNet) : ''));
$showOldPrice = ($mainBefore !== '') && $allowOldPriceInThisView;

$mainAfter = ($afterGross !== null) ? money($afterGross) : (($afterNet !== null) ? money($afterNet) : '');

/* Period label */
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
	  --border:rgba(15,23,42,.14);
	  --shadow:0 8px 22px rgba(0,0,0,.08);
	}

	*{ box-sizing:border-box; }
	body{
	  margin:0;
	  font-family: ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Arial;
	  color:var(--text);
	}

	.wrap{ padding:10px; display:flex; justify-content:center; }

	.card{
	  width:100%;
	  max-width:320px;
	  background: var(--card);
	  border:1px solid var(--border);
	  border-radius:16px;
	  box-shadow: var(--shadow);
	  overflow:hidden;
	  float:left;
	}

	/* ✅ Querformat-Layout (nur Format) */
	.card.pv-landscape{
	  max-width:900px;
	  display:flex;
	  flex-direction:row;
	  align-items:stretch;
	}
	/* Auto: nur wenn Gerät landscape UND layout=auto */
	@media (orientation: landscape){
	  .card.pv-auto-landscape{
		max-width:900px;
		display:flex;
		flex-direction:row;
		align-items:stretch;
	  }
	}
	/* Responsive: auf kleineren Screens trotz Landscape wieder stacken */
	@media (max-width:860px){
	  .card.pv-landscape{ display:block; max-width:320px; }
	  @media (orientation: landscape){
		.card.pv-auto-landscape{ display:block; max-width:320px; }
	  }
	}

	/* ✅ nur für Querformat: linke/rechte Spalte */
	.pv-col-left{ flex: 0 0 340px; border-right:1px solid var(--border); }
	.pv-col-right{ flex: 1 1 auto; }

	/* Wenn gestackt, Border wieder weg */
	@media (max-width:860px){
	  .pv-col-left{ border-right:0; }
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

	.subwrap{ padding:0 12px 10px 12px; }
	.sub{
	  color:var(--muted);
	  font-size:13px;
	  margin:0;
	  line-height:1.35;
	  white-space:pre-wrap;
	}

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

	/* ✅ Querformat: etwas kompaktere Ränder, damit mehr "quer" wirkt */
	.card.pv-landscape .img,
	.card.pv-auto-landscape .img{ margin:12px; }
	.card.pv-landscape .box,
	.card.pv-auto-landscape .box{ margin:12px; }
  </style>
</head>
<body>
  <div class="wrap">
	<?php
	  // ✅ Nur Klassen setzen (kein Inhalt geändert)
	  $cardClass = 'card';
	  if ($layout === 'landscape') $cardClass .= ' pv-landscape';
	  elseif ($layout === 'auto')  $cardClass .= ' pv-auto-landscape';
	  // portrait => keine Zusatzklasse
	?>
	<div class="<?= h($cardClass) ?>">

	  <?php
		// ✅ Bei Querformat/Auto-Landscape: Inhalt in 2 Spalten,
		//    ansonsten exakt wie vorher (nur Wrapper-Struktur).
		$isLandscapeLayout = ($layout === 'landscape');
		$isAutoLandscapeWrapper = ($layout === 'auto'); // CSS entscheidet per orientation
	  ?>

	  <?php if ($layout === 'landscape' || $layout === 'auto'): ?>
		<div class="pv-col-left">
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
		</div>

		<div class="pv-col-right">
		  <div class="box">
			<div class="price-row">
			  <?php if ($hasAfter): ?>

				<?php if ($showOldPrice): ?>
				  <div class="old-wrap" aria-label="Originalpreis">
					<div class="old-price"><?= h($mainBefore) ?></div>
				  </div>
				<?php endif; ?>

				<p class="new-price"><?= h($mainAfter !== '' ? $mainAfter : 'Preis auf Anfrage') ?></p>

				<?php if ($afterGross !== null && $afterNet !== null): ?>
				  <div class="muted">Netto: <?= h(money($afterNet)) ?></div>
				<?php endif; ?>

			  <?php else: ?>
				<?php if ($showOldPrice): ?>
				  <div class="old-wrap" aria-label="Originalpreis">
					<div class="old-price"><?= h($mainBefore) ?></div>
				  </div>
				<?php endif; ?>
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
		</div>

	  <?php else: ?>
		<!-- ✅ Portrait (Original unverändert) -->
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

			  <?php if ($showOldPrice): ?>
				<div class="old-wrap" aria-label="Originalpreis">
				  <div class="old-price"><?= h($mainBefore) ?></div>
				</div>
			  <?php endif; ?>

			  <p class="new-price"><?= h($mainAfter !== '' ? $mainAfter : 'Preis auf Anfrage') ?></p>

			  <?php if ($afterGross !== null && $afterNet !== null): ?>
				<div class="muted">Netto: <?= h(money($afterNet)) ?></div>
			  <?php endif; ?>

			<?php else: ?>
			  <?php if ($showOldPrice): ?>
				<div class="old-wrap" aria-label="Originalpreis">
				  <div class="old-price"><?= h($mainBefore) ?></div>
				</div>
			  <?php endif; ?>
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
	  <?php endif; ?>

	</div>
  </div>
</body>
</html>
