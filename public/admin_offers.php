<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/OfferRepository.php';

use PV\Repository;
use PV\OfferRepository;

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

/**
 * Robustere Zahl-Erkennung:
 * - entfernt €, NBSP, Leerzeichen
 * - entfernt sonstige Zeichen (*, EUR, etc.)
 * - 1.234,56 -> 1234.56
 */
function num_or_null($v): ?float {
  if ($v === null) return null;
  if (is_float($v) || is_int($v)) return (float)$v;

  $s = trim((string)$v);
  if ($s === '') return null;

  $s = str_replace(["\u{00A0}", ' '], '', $s);
  $s = str_replace(['€'], '', $s);

  $s = preg_replace('~[^0-9,\.\-]~', '', $s);
  if ($s === '' || $s === '-') return null;

  $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);

  if (!is_numeric($s)) return null;
  return (float)$s;
}

function money(?float $v): string {
  if ($v === null) return '';
  return number_format($v, 2, ',', '.') . ' €';
}

function pv_new_offer_id(): string {
  return 'of_' . rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
}

function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function pv_try_repo_config(string $configPath, string $productKey): array {
  try {
	$repo = new Repository($configPath, $productKey);
	$cfg = $repo->getConfig();
	return is_array($cfg) ? $cfg : [];
  } catch (Throwable $e) {
	return [];
  }
}

function pv_extract_variants(array $store): array {
  if (isset($store['variants']) && is_array($store['variants'])) {
	return $store['variants'];
  }
  if (isset($store['data']) && is_array($store['data'])) {
	return $store['data'];
  }
  if (array_is_list($store)) {
	return $store;
  }
  return [];
}

function pv_detect_primary_key(array $variants, string $preferred = ''): string {
  $preferred = trim($preferred);

  if ($preferred !== '') {
	foreach ($variants as $row) {
	  if (is_array($row) && array_key_exists($preferred, $row) && trim((string)$row[$preferred]) !== '') {
		return $preferred;
	  }
	}
  }

  $candidates = [
	'Artiklnummer',
	'Artikelnummer',
	'Artikel-Nr.',
	'Artikel Nr.',
	'ArtikelNr',
	'SKU',
	'Sku',
	'sku',
	'article',
	'article_number',
	'id',
	'ID',
	'Nr',
	'nr'
  ];

  foreach ($candidates as $candidate) {
	foreach ($variants as $row) {
	  if (is_array($row) && array_key_exists($candidate, $row) && trim((string)$row[$candidate]) !== '') {
		return $candidate;
	  }
	}
  }

  foreach ($variants as $row) {
	if (!is_array($row)) continue;
	foreach ($row as $k => $v) {
	  if (is_string($k) && trim((string)$v) !== '') {
		return $k;
	  }
	}
  }

  return 'Artiklnummer';
}

function pv_detect_price_keys(array $found, array $cfg): array {
  $grossKeyCfg = (string)($cfg['variant']['price_gross'] ?? '');
  $netKeyCfg   = (string)($cfg['variant']['price_net'] ?? '');

  $grossKeys = array_values(array_unique(array_filter([
	$grossKeyCfg,
	'mit MwSt. €',
	'mit MwSt.',
	'Brutto',
	'Preis Brutto',
	'VK Brutto',
	'price_gross',
	'gross',
	'brutto'
  ], fn($x) => is_string($x) && trim($x) !== '')));

  $netKeys = array_values(array_unique(array_filter([
	$netKeyCfg,
	'ohne MwSt. €',
	'ohne MwSt.',
	'Netto',
	'Preis Netto',
	'VK Netto',
	'price_net',
	'net',
	'netto'
  ], fn($x) => is_string($x) && trim($x) !== '')));

  $gross = null;
  foreach ($grossKeys as $k) {
	if (array_key_exists($k, $found)) {
	  $gross = num_or_null($found[$k]);
	  if ($gross !== null) break;
	}
  }

  $net = null;
  foreach ($netKeys as $k) {
	if (array_key_exists($k, $found)) {
	  $net = num_or_null($found[$k]);
	  if ($net !== null) break;
	}
  }

  return [$gross, $net];
}

/* Token-Check */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

$offersRepo = new OfferRepository(__DIR__ . '/../config/offers.json');

/* Produkte aus /data/products */
function pv_products(): array {
  $out = [];
  $base = __DIR__ . '/../data/products';
  if (!is_dir($base)) return $out;

  foreach (scandir($base) ?: [] as $d) {
	if ($d === '.' || $d === '..') continue;
	$path = $base . '/' . $d;
	if (!is_dir($path)) continue;

	$key = pv_key($d);
	if ($key === '') continue;

	$label = $key;
	$vfile = $path . '/variants.json';
	if (is_file($vfile)) {
	  $variantsRaw = json_decode((string)file_get_contents($vfile), true);
	  $variants = is_array($variantsRaw) ? pv_extract_variants($variantsRaw) : [];
	  if (!empty($variants[0]) && is_array($variants[0])) {
		$v0 = $variants[0];
		if (!empty($v0['Produktgruppe']) && !empty($v0['Produktart'])) {
		  $label = trim((string)$v0['Produktgruppe'] . ' ' . (string)$v0['Produktart']);
		} elseif (!empty($v0['Produktart'])) {
		  $label = trim((string)$v0['Produktart']);
		}
	  }
	}

	$out[$key] = $label;
  }

  ksort($out);
  return $out;
}

function pv_variants_store_path(string $productKey): string {
  return __DIR__ . '/../data/products/' . $productKey . '/variants.json';
}

function pv_load_variants_store(string $productKey): array {
  $p = pv_variants_store_path($productKey);
  if (!is_file($p)) return [];
  $raw = json_decode((string)file_get_contents($p), true);
  return is_array($raw) ? $raw : [];
}

function pv_variants_for_product(string $configPath, string $productKey): array {
  $cfg = pv_try_repo_config($configPath, $productKey);
  $store = pv_load_variants_store($productKey);

  $vat = null;
  if (isset($store['vat_rate']) && is_numeric($store['vat_rate'])) {
	$vat = (float)$store['vat_rate'];
  } elseif (isset($cfg['import']['vat_rate']) && is_numeric($cfg['import']['vat_rate'])) {
	$vat = (float)$cfg['import']['vat_rate'];
  } elseif (isset($cfg['vat_rate']) && is_numeric($cfg['vat_rate'])) {
	$vat = (float)$cfg['vat_rate'];
  }

  $vars = pv_extract_variants($store);
  $pkPreferred = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');
  $pk = pv_detect_primary_key($vars, $pkPreferred);

  $list = [];
  foreach ($vars as $v) {
	if (!is_array($v)) continue;

	$id = trim((string)($v[$pk] ?? ''));
	if ($id === '') continue;

	$labelParts = [];
	if (!empty($v['Produktgruppe'])) $labelParts[] = trim((string)$v['Produktgruppe']);
	if (!empty($v['Produktart'])) $labelParts[] = trim((string)$v['Produktart']);

	$label = !empty($labelParts)
	  ? implode(' ', $labelParts) . ' · ' . $id
	  : $id;

	$list[$id] = $label;
  }

  ksort($list);
  return [$pk, $list, $vat];
}

function pv_variant_list_price(string $configPath, string $productKey, string $article): array {
  $vat = 0.19;

  try {
	$cfg = pv_try_repo_config($configPath, $productKey);
	$store = pv_load_variants_store($productKey);

	if (isset($store['vat_rate']) && is_numeric($store['vat_rate'])) {
	  $vat = (float)$store['vat_rate'];
	} elseif (isset($cfg['import']['vat_rate']) && is_numeric($cfg['import']['vat_rate'])) {
	  $vat = (float)$cfg['import']['vat_rate'];
	} elseif (isset($cfg['vat_rate']) && is_numeric($cfg['vat_rate'])) {
	  $vat = (float)$cfg['vat_rate'];
	}

	$vars = pv_extract_variants($store);
	$pkPreferred = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');
	$pk = pv_detect_primary_key($vars, $pkPreferred);

	$found = null;
	foreach ($vars as $v) {
	  if (!is_array($v)) continue;
	  if ((string)($v[$pk] ?? '') === $article) {
		$found = $v;
		break;
	  }
	}

	if (!is_array($found)) return [null, null, $vat];

	[$gross, $net] = pv_detect_price_keys($found, $cfg);

	if ($net === null && $gross !== null && $vat >= 0) $net = $gross / (1.0 + $vat);
	if ($gross === null && $net !== null && $vat >= 0) $gross = $net * (1.0 + $vat);

	return [$gross, $net, $vat];
  } catch (Throwable $e) {
	return [null, null, $vat];
  }
}

/* Daten laden */
$products  = pv_products();
$allOffers = $offersRepo->load();

$selProduct = pv_key((string)($_GET['product'] ?? $_POST['product'] ?? ''));
if ($selProduct === '' && !empty($products)) $selProduct = (string)array_key_first($products);

$editId = trim((string)($_GET['edit'] ?? ''));
$edit = ($editId !== '' && isset($allOffers[$editId]) && is_array($allOffers[$editId])) ? $allOffers[$editId] : null;

/* Produkt/Variante für UI: bei Edit -> aus Angebot, sonst aus GET */
$uiProduct = $edit ? pv_key((string)($edit['product'] ?? $selProduct)) : $selProduct;

/* Variantenliste */
[$pkLabel, $variantsList, $vatFromVariants] = ($uiProduct !== '')
  ? pv_variants_for_product($configPath, $uiProduct)
  : ['Artiklnummer', [], null];

/* uiArticle bestimmen */
$uiArticle = '';
if ($edit) {
  $uiArticle = trim((string)($edit['article'] ?? ''));
} else {
  $uiArticle = trim((string)($_GET['article'] ?? ''));
}

if ($uiArticle !== '' && !isset($variantsList[$uiArticle])) {
  $uiArticle = '';
}
if ($uiArticle === '' && !empty($variantsList)) {
  $uiArticle = (string)array_key_first($variantsList);
}

[$listGross, $listNet, $vatRate] = ($uiProduct !== '' && $uiArticle !== '')
  ? pv_variant_list_price($configPath, $uiProduct, $uiArticle)
  : [null, null, (is_numeric($vatFromVariants) ? (float)$vatFromVariants : 0.19)];

/* Flash */
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);

/* SAVE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_offer') {
  try {
	$id = trim((string)($_POST['id'] ?? ''));
	$isNew = ($id === '');
	if ($isNew) $id = pv_new_offer_id();

	$product = pv_key((string)($_POST['product'] ?? ''));
	$article = trim((string)($_POST['article'] ?? ''));

	if ($product === '') throw new RuntimeException('Produkt fehlt.');
	if ($article === '') throw new RuntimeException('Variante/' . $pkLabel . ' fehlt.');

	[$lpGross, $lpNet, $vat] = pv_variant_list_price($configPath, $product, $article);

	$offerGrossRaw = trim((string)($_POST['offer_price_gross'] ?? ''));
	$offerGross = num_or_null($offerGrossRaw);
	if ($offerGross === null) throw new RuntimeException('Bitte Angebotspreis (Brutto) eingeben.');

	$offerNet = ($vat >= 0) ? ($offerGross / (1.0 + $vat)) : null;

	$title    = trim((string)($_POST['title'] ?? ''));
	$badge    = trim((string)($_POST['badge'] ?? 'Angebot'));
	$subtitle = trim((string)($_POST['subtitle'] ?? ''));
	$starts   = trim((string)($_POST['starts_at'] ?? ''));
	$ends     = trim((string)($_POST['ends_at'] ?? ''));
	$active   = ((string)($_POST['active'] ?? '0') === '1');

	if ($starts !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $starts)) throw new RuntimeException('Startdatum muss YYYY-MM-DD sein (oder leer).');
	if ($ends   !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $ends))   throw new RuntimeException('Enddatum muss YYYY-MM-DD sein (oder leer).');

	$embedShowListPrice = ((string)($_POST['embed_show_list_price'] ?? '0') === '1');

	$ctaUrl = '/index.php?product=' . rawurlencode($product)
		   . '&article=' . rawurlencode($article)
		   . '&offer=' . rawurlencode($id)
		   . '&autocart=1';

	$now = date('c');

	$allOffers[$id] = [
	  'id' => $id,
	  'active' => $active,

	  'product' => $product,
	  'article' => $article,

	  'title' => $title,
	  'badge' => $badge,
	  'subtitle' => $subtitle,

	  'before_price_gross' => $lpGross,
	  'before_price_net'   => $lpNet,

	  'price_gross' => $offerGross,
	  'price_net'   => $offerNet,

	  'starts_at' => $starts,
	  'ends_at' => $ends,

	  'embed_show_list_price' => $embedShowListPrice,

	  'cta_label' => 'In den Warenkorb',
	  'cta_url' => $ctaUrl,

	  'created_at' => $allOffers[$id]['created_at'] ?? $now,
	  'updated_at' => $now,
	];

	$offersRepo->save($allOffers);

	$_SESSION['pv_flash'] = $isNew ? ('Angebot angelegt: ' . $id) : ('Angebot gespeichert: ' . $id);
	header('Location: admin_offers.php?token=' . urlencode($token) . '&product=' . urlencode($product) . '&article=' . urlencode($article) . '&edit=' . urlencode($id));
	exit;
  } catch (Throwable $e) {
	$_SESSION['pv_flash'] = 'Fehler: ' . $e->getMessage();
	header('Location: admin_offers.php?token=' . urlencode($token) . '&product=' . urlencode($uiProduct));
	exit;
  }
}

/* DELETE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete_offer') {
  try {
	$id = trim((string)($_POST['id'] ?? ''));
	if ($id === '' || !isset($allOffers[$id])) throw new RuntimeException('Offer nicht gefunden.');
	unset($allOffers[$id]);
	$offersRepo->save($allOffers);

	$_SESSION['pv_flash'] = 'Angebot gelöscht: ' . $id;
	header('Location: admin_offers.php?token=' . urlencode($token) . '&product=' . urlencode($uiProduct));
	exit;
  } catch (Throwable $e) {
	$_SESSION['pv_flash'] = 'Fehler: ' . $e->getMessage();
	header('Location: admin_offers.php?token=' . urlencode($token) . '&product=' . urlencode($uiProduct));
	exit;
  }
}

/* Public offer base URL */
$offerBase = pv_base_url() . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/offer.php';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Angebote</title>
  <link rel="stylesheet" href="assets/styles.css">

  <style>
	:root{
	  --bg:#ffffff;
	  --card:#ffffff;
	  --text:#0f172a;
	  --muted:rgba(15,23,42,.65);
	  --border:rgba(15,23,42,.14);
	  --accent:#2563eb;
	  --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; color: var(--text) !important; }
	.card{ background: var(--card) !important; }
	.h-sub, .small{ color: var(--muted) !important; }
	input[type="text"], input[type="number"], textarea, select{
	  background: rgba(15,23,42,.03) !important;
	  color: var(--text) !important;
	}
	textarea{ border:1px solid var(--border) !important; }
	.btn{ background: linear-gradient(135deg, rgba(37,99,235,.12), rgba(15,23,42,.02)) !important; }
	.btn:hover{ border-color: rgba(37,99,235,.55) !important; }

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }

	.row2{ display:grid; grid-template-columns:1fr 1fr; gap:10px; }
	@media (max-width:820px){ .row2{ grid-template-columns:1fr; } }

	.note{
	  border:1px solid var(--border);
	  border-radius:14px;
	  padding:12px;
	  background: rgba(15,23,42,.02);
	}
	.mono code{ font-size:12px; }

	.pv-actions{
	  display:flex;
	  flex-wrap:wrap;
	  gap:6px;
	  align-items:center;
	  justify-content:flex-end;
	}
	.pv-actions .btn{
	  display:inline-flex !important;
	  align-items:center;
	  justify-content:center;
	  height:32px;
	  line-height:30px;
	  padding:0 10px;
	  white-space:nowrap;
	  flex:0 0 auto;
	}
	.pv-actions form{ margin:0; }
	.pv-table td.actions-cell{ white-space:normal !important; }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Angebote</div>
		<div class="h-sub">Workflow: Produkt wählen → Listenpreis laden → Angebotspreis (nur Brutto) → CTA legt in Warenkorb.</div>
	  </div>
	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Zurück zu Admin</a>
		<a class="btn" href="index.php">Frontend</a>
	  </div>
	</div>

	<?php if ($flash): ?>
	  <div class="card" style="border-radius:14px"><div class="card-b"><?= h($flash) ?></div></div>
	  <div style="height:10px"></div>
	<?php endif; ?>

	<div class="card" style="border-radius:14px; margin-bottom:12px;">
	  <div class="card-h">
		<div class="card-title">Produkt wählen</div>
		<div class="small">Beim Wechsel wird die Variantenliste + Listenpreis aktualisiert.</div>
	  </div>
	  <div class="card-b">
		<form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin:0">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <div style="min-width:320px">
			<label>Produkt</label>
			<select name="product" onchange="this.form.submit()">
			  <?php foreach ($products as $k => $lab): ?>
				<option value="<?= h($k) ?>" <?= $k === $uiProduct ? 'selected' : '' ?>><?= h($lab) ?></option>
			  <?php endforeach; ?>
			</select>
		  </div>
		  <?php if ($editId !== ''): ?>
			<input type="hidden" name="edit" value="<?= h($editId) ?>">
		  <?php endif; ?>
		  <noscript><button class="btn" type="submit">Laden</button></noscript>
		</form>
	  </div>
	</div>

	<div class="card" style="border-radius:14px; margin-bottom:12px;">
	  <div class="card-h">
		<div class="card-title">Angebot <?= $edit ? 'bearbeiten' : 'anlegen' ?></div>
		<div class="small">Listenpreis wird aus <code>data/products/<?= h($uiProduct) ?>/variants.json</code> gelesen.</div>
	  </div>
	  <div class="card-b">

		<form method="get" style="margin:0 0 12px 0">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="product" value="<?= h($uiProduct) ?>">
		  <?php if ($editId !== ''): ?>
			<input type="hidden" name="edit" value="<?= h($editId) ?>">
		  <?php endif; ?>
		  <label>Variante (<?= h($pkLabel) ?>)</label>
		  <select name="article" onchange="this.form.submit()">
			<?php if (empty($variantsList)): ?>
			  <option value="">Keine Varianten gefunden</option>
			<?php else: ?>
			  <?php foreach ($variantsList as $idv => $lab): ?>
				<option value="<?= h($idv) ?>" <?= $idv === $uiArticle ? 'selected' : '' ?>><?= h($lab) ?></option>
			  <?php endforeach; ?>
			<?php endif; ?>
		  </select>
		  <div class="small" style="margin-top:6px">Variante wechseln → Listenpreis wird aktualisiert.</div>
		</form>

		<?php
		  $editIdSafe = $edit ? (string)($edit['id'] ?? '') : '';
		  $publicUrl = ($editIdSafe !== '') ? ($offerBase . '?id=' . rawurlencode($editIdSafe)) : '';
		  $publicUrlEmbed = ($editIdSafe !== '') ? ($offerBase . '?id=' . rawurlencode($editIdSafe) . '&embed=1') : '';

		  $offerGrossExisting = $edit ? num_or_null($edit['price_gross'] ?? null) : null;
		  $offerNetExisting   = $edit ? num_or_null($edit['price_net'] ?? null) : null;

		  $embedShowListPriceExisting = $edit ? (!empty($edit['embed_show_list_price'])) : false;
		  $offerGrossExistingDe = ($offerGrossExisting !== null) ? number_format($offerGrossExisting, 2, ',', '.') : '';
		?>

		<div class="note" style="margin-bottom:12px;">
		  <div class="small" style="font-weight:900; margin-bottom:6px;">Listenpreis (aus variants.json)</div>
		  <div class="row2">
			<div>
			  <label>Listenpreis Brutto</label>
			  <input type="text" readonly value="<?= h($listGross !== null ? money($listGross) : '—') ?>">
			</div>
			<div>
			  <label>Listenpreis Netto</label>
			  <input type="text" readonly value="<?= h($listNet !== null ? money($listNet) : '—') ?>">
			</div>
		  </div>
		  <div class="small" style="margin-top:8px;">
			MwSt-Satz: <strong><?= h((string)round($vatRate * 100, 2)) ?>%</strong>
		  </div>
		</div>

		<form method="post" autocomplete="off" id="offer_save_form" style="margin:0">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="save_offer">
		  <input type="hidden" name="id" value="<?= h($editIdSafe) ?>">

		  <input type="hidden" name="product" value="<?= h($uiProduct) ?>">
		  <input type="hidden" name="article" value="<?= h($uiArticle) ?>">

		  <div class="row2">
			<div>
			  <label>Titel (optional)</label>
			  <input type="text" name="title" value="<?= h((string)($edit['title'] ?? '')) ?>">
			</div>
			<div>
			  <label>Badge (optional)</label>
			  <input type="text" name="badge" value="<?= h((string)($edit['badge'] ?? 'Angebot')) ?>">
			</div>
		  </div>

		  <div style="height:10px"></div>

		  <label>Untertitel / Kurztext (optional)</label>
		  <textarea name="subtitle" style="min-height:90px; padding:12px; border-radius:12px;"><?= h((string)($edit['subtitle'] ?? '')) ?></textarea>

		  <div style="height:10px"></div>

		  <div class="row2">
			<div>
			  <label>Angebotspreis Brutto</label>
			  <input id="offer_gross"
					 type="text"
					 inputmode="decimal"
					 name="offer_price_gross"
					 value="<?= h($offerGrossExistingDe) ?>"
					 placeholder="z.B. 199,00"
					 required>
			  <div class="small" style="margin-top:6px">Nur Brutto eingeben – Netto wird automatisch berechnet. (Komma ist erlaubt.)</div>
			</div>
			<div>
			  <label>Angebotspreis Netto (berechnet)</label>
			  <input id="offer_net_preview" type="text" readonly
					 value="<?= h($offerNetExisting !== null ? money($offerNetExisting) : '') ?>">
			</div>
		  </div>

		  <div style="height:10px"></div>

		  <div class="row2">
			<div>
			  <label>Start (optional, YYYY-MM-DD)</label>
			  <input type="text" name="starts_at" value="<?= h((string)($edit['starts_at'] ?? '')) ?>" placeholder="2026-01-20">
			</div>
			<div>
			  <label>Ende (optional, YYYY-MM-DD)</label>
			  <input type="text" name="ends_at" value="<?= h((string)($edit['ends_at'] ?? '')) ?>" placeholder="2026-02-10">
			</div>
		  </div>

		  <div style="height:10px"></div>

		  <label style="display:flex; gap:10px; align-items:center; margin:0">
			<input type="hidden" name="active" value="0">
			<input type="checkbox" name="active" value="1" <?= !empty($edit['active']) ? 'checked' : '' ?> style="width:18px; height:18px;">
			<span class="small" style="font-weight:800">Aktiv</span>
		  </label>

		  <div style="height:12px"></div>

		  <label style="display:flex; gap:10px; align-items:center; margin:0">
			<input type="hidden" name="embed_show_list_price" value="0">
			<input type="checkbox" name="embed_show_list_price" value="1" <?= $embedShowListPriceExisting ? 'checked' : '' ?> style="width:18px; height:18px;">
			<span class="small" style="font-weight:800">Listenpreis im iFrame anzeigen</span>
		  </label>
		  <div class="small" style="margin-top:6px;">
			Wirkung nur im iFrame/Embed (offer.php?embed=1). Öffentliche URL bleibt unverändert.
		  </div>

		  <div style="height:12px"></div>

		  <button class="btn" type="submit" <?= empty($variantsList) ? 'disabled' : '' ?>>Speichern</button>
		  <a class="btn" href="admin_offers.php?token=<?= h(urlencode($token)) ?>&product=<?= h(urlencode($uiProduct)) ?>" style="margin-left:8px">Neu anlegen</a>
		</form>

		<?php if ($editIdSafe !== ''): ?>
		  <?php
			$iframePortrait =
			  '<iframe src="' . htmlspecialchars($publicUrlEmbed, ENT_QUOTES, 'UTF-8') . '" ' .
			  'style="width:320px; height:900px; border:0; border-radius:16px; overflow:hidden" ' .
			  'loading="lazy"></iframe>';

			$iframeLandscape =
			  '<iframe src="' . htmlspecialchars($publicUrlEmbed, ENT_QUOTES, 'UTF-8') . '" ' .
			  'style="width:900px; height:320px; border:0; border-radius:16px; overflow:hidden" ' .
			  'loading="lazy"></iframe>';

			$iframeAuto =
			  '<div class="pv-offer-embed-wrap">' . "\n" .
			  '  <style>' . "\n" .
			  '    .pv-offer-embed-wrap{max-width:360px}' . "\n" .
			  '    .pv-offer-embed{' . "\n" .
			  '      width:100%; height:auto; aspect-ratio:9/16;' . "\n" .
			  '      border:0; border-radius:16px; overflow:hidden; display:block;' . "\n" .
			  '    }' . "\n" .
			  '    @media (orientation: landscape){' . "\n" .
			  '      .pv-offer-embed-wrap{max-width:960px}' . "\n" .
			  '      .pv-offer-embed{aspect-ratio:16/9}' . "\n" .
			  '    }' . "\n" .
			  '  </style>' . "\n" .
			  '  <iframe class="pv-offer-embed" src="' . htmlspecialchars($publicUrlEmbed, ENT_QUOTES, 'UTF-8') . '" loading="lazy"></iframe>' . "\n" .
			  '</div>';
		  ?>

		  <div style="height:14px"></div>
		  <div class="note mono">
			<div class="small" style="font-weight:900; margin-bottom:6px;">Embed</div>
			<div class="small"><strong>Public URL:</strong> <code><?= h($publicUrl) ?></code></div>
			<div class="small" style="margin-top:6px;"><strong>Embed URL:</strong> <code><?= h($publicUrlEmbed) ?></code></div>

			<div style="height:10px"></div>

			<div class="small"><strong>iFrame Hochformat:</strong></div>
			<textarea id="iframe_code_portrait" readonly style="width:100%; min-height:110px; padding:12px; border-radius:12px;"><?= h($iframePortrait) ?></textarea>
			<div style="height:10px"></div>
			<button type="button" class="btn" data-copy-target="iframe_code_portrait">Hochformat kopieren</button>

			<div style="height:14px"></div>

			<div class="small"><strong>iFrame Querformat:</strong></div>
			<textarea id="iframe_code_landscape" readonly style="width:100%; min-height:110px; padding:12px; border-radius:12px;"><?= h($iframeLandscape) ?></textarea>
			<div style="height:10px"></div>
			<button type="button" class="btn" data-copy-target="iframe_code_landscape">Querformat kopieren</button>

			<div style="height:14px"></div>

			<div class="small"><strong>iFrame Auto (Portrait/Querformat):</strong></div>
			<textarea id="iframe_code_auto" readonly style="width:100%; min-height:170px; padding:12px; border-radius:12px;"><?= h($iframeAuto) ?></textarea>
			<div style="height:10px"></div>
			<button type="button" class="btn" data-copy-target="iframe_code_auto">Auto-Code kopieren</button>

			<div style="height:10px"></div>
			<a class="btn" target="_blank" href="<?= h($publicUrlEmbed) ?>">Vorschau öffnen</a>

			<form method="post" style="display:inline-block; margin-left:8px" onsubmit="return confirm('Angebot wirklich löschen?')">
			  <input type="hidden" name="token" value="<?= h($token) ?>">
			  <input type="hidden" name="action" value="delete_offer">
			  <input type="hidden" name="id" value="<?= h($editIdSafe) ?>">
			  <button class="btn" type="submit">Löschen</button>
			</form>
		  </div>
		<?php endif; ?>

	  </div>
	</div>

	<div class="card" style="border-radius:14px;">
	  <div class="card-h">
		<div class="card-title">Alle Angebote</div>
		<div class="small">Bearbeiten/Preview per Klick.</div>
	  </div>
	  <div class="card-b">
		<?php if (empty($allOffers)): ?>
		  <div class="small">Noch keine Angebote vorhanden.</div>
		<?php else: ?>
		  <table class="pv-table">
			<thead>
			  <tr>
				<th>ID</th>
				<th>Status</th>
				<th>Produkt</th>
				<th>Variante</th>
				<th>Listenpreis</th>
				<th>Angebot</th>
				<th>iFrame LP</th>
				<th style="text-align:right">Aktion</th>
			  </tr>
			</thead>
			<tbody>
			  <?php foreach ($allOffers as $id3 => $o): if (!is_array($o)) continue; ?>
				<?php
				  $status = !empty($o['active']) ? 'Aktiv' : 'Inaktiv';
				  $p = (string)($o['product'] ?? '');
				  $a = (string)($o['article'] ?? '');

				  $b = num_or_null($o['before_price_gross'] ?? null);
				  if ($b === null && $p !== '' && $a !== '') {
					[$bg, $bn] = pv_variant_list_price($configPath, $p, $a);
					$b = $bg;
				  }

				  $n = num_or_null($o['price_gross'] ?? null);
				  $public = $offerBase . '?id=' . rawurlencode((string)$id3);
				  $publicEmbed = $offerBase . '?id=' . rawurlencode((string)$id3) . '&embed=1';
				  $embedFlag = !empty($o['embed_show_list_price']);
				?>
				<tr>
				  <td><span class="pv-pill"><?= h((string)$id3) ?></span></td>
				  <td><?= h($status) ?></td>
				  <td><?= h($p) ?></td>
				  <td><?= h($a) ?></td>
				  <td><?= h($b !== null ? money($b) : '—') ?></td>
				  <td><?= h($n !== null ? money($n) : '—') ?></td>
				  <td><?= $embedFlag ? '<span class="pv-pill">An</span>' : '<span class="pv-pill">Aus</span>' ?></td>
				  <td class="actions-cell">
					<div class="pv-actions">
					  <a class="btn"
						 href="admin_offers.php?token=<?= h(urlencode($token)) ?>&product=<?= h(urlencode($p)) ?>&article=<?= h(urlencode($a)) ?>&edit=<?= h(urlencode((string)$id3)) ?>">Bearbeiten</a>

					  <a class="btn" target="_blank"
						 href="<?= h($publicEmbed) ?>">Vorschau</a>

					  <form method="post" onsubmit="return confirm('Angebot wirklich löschen?')">
						<input type="hidden" name="token" value="<?= h($token) ?>">
						<input type="hidden" name="action" value="delete_offer">
						<input type="hidden" name="id" value="<?= h((string)$id3) ?>">
						<button class="btn" type="submit">Löschen</button>
					  </form>
					</div>
				  </td>
				</tr>
			  <?php endforeach; ?>
			</tbody>
		  </table>
		<?php endif; ?>
	  </div>
	</div>

  </div>

  <script>
	(function(){
	  const vat = <?= json_encode((float)$vatRate) ?>;
	  const gross = document.getElementById('offer_gross');
	  const netOut = document.getElementById('offer_net_preview');

	  function fmtEUR(n){
		try {
		  return n.toLocaleString('de-DE', {minimumFractionDigits:2, maximumFractionDigits:2}) + ' €';
		} catch(e){
		  return (Math.round(n*100)/100).toFixed(2).replace('.',',') + ' €';
		}
	  }

	  function parseMoney(s){
		if(s == null) return NaN;
		s = String(s).trim();
		if(!s) return NaN;

		s = s.replace(/\u00A0/g, '').replace(/\s+/g, '');
		s = s.replace(/€/g, '');
		s = s.replace(/[^0-9,.\-]/g, '');
		if(!s || s === '-') return NaN;

		s = s.replace(/\./g, '').replace(/,/g, '.');

		const n = parseFloat(s);
		return Number.isFinite(n) ? n : NaN;
	  }

	  function update(){
		if(!gross || !netOut) return;
		const v = (gross.value || '').trim();
		if(v === '') { netOut.value = ''; return; }

		const g = parseMoney(v);
		if(!isFinite(g) || g <= 0) { netOut.value = ''; return; }

		const n = g / (1 + vat);
		netOut.value = fmtEUR(n);
	  }

	  if(gross){
		gross.addEventListener('input', update);
		gross.addEventListener('change', update);
		update();
	  }

	  function copyTextFrom(el){
		if(!el) return;
		const text = el.value ?? el.textContent ?? '';
		if(navigator.clipboard && navigator.clipboard.writeText){
		  navigator.clipboard.writeText(text).catch(()=>fallbackCopy(el));
		} else {
		  fallbackCopy(el);
		}
	  }

	  function fallbackCopy(el){
		try{
		  el.focus();
		  el.select();
		  document.execCommand('copy');
		}catch(e){}
	  }

	  document.querySelectorAll('[data-copy-target]').forEach(btn=>{
		btn.addEventListener('click', ()=>{
		  const id = btn.getAttribute('data-copy-target');
		  const el = document.getElementById(id);
		  copyTextFrom(el);
		});
	  });
	})();
  </script>
</body>
</html>