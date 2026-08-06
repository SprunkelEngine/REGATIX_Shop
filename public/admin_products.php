<?php

<?php
declare(strict_types=1);

// DEBUG nur bei ?debug=1
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Admin.php';

use PV\Repository;
use PV\Admin;

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}
function pv_safe_path(string $s): string {
  return preg_replace('~[^A-Za-z0-9_\-]~', '_', $s) ?: 'x';
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===========================
   ✅ Auth Token
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   ✅ Produktliste aus /data/products/<key>/… (automatisch)
   - Label aus variants.json (Produktgruppe + Produktart)
   - Eckregal wird NICHT angeboten
   =========================== */
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
	$isEckregal = false;

	$vfile = $path . '/variants.json';
	if (is_file($vfile)) {
	  $variants = json_decode((string)file_get_contents($vfile), true);

	  if (is_array($variants) && isset($variants['variants']) && is_array($variants['variants'])) {
		$variants = $variants['variants'];
	  }

	  if (is_array($variants) && !empty($variants[0]) && is_array($variants[0])) {
		$v0 = $variants[0];

		if (!empty($v0['Produktgruppe']) && !empty($v0['Produktart'])) {
		  $label = trim((string)$v0['Produktgruppe'] . ' ' . (string)$v0['Produktart']);
		} elseif (!empty($v0['Produktart'])) {
		  $label = trim((string)$v0['Produktart']);
		} elseif ($key === 'fachbodenregal') {
		  $label = 'Fachbodenregal';
		}

		if (isset($v0['Produktart']) && stripos((string)$v0['Produktart'], 'Eckregal') !== false) {
		  $isEckregal = true;
		}
	  }
	} else {
	  if ($key === 'fachbodenregal') $label = 'Fachbodenregal';
	}

	if ($isEckregal) continue;
	$out[$key] = $label;
  }

  ksort($out);
  return $out;
}

/* ===========================
   ✅ Produkt-spezifische Felder (fields.json)
   =========================== */
function pv_fields_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/fields.json';
}
function pv_default_fields_template(): array {
  return [
	'overrides' => [
	  ['key'=>'Ebenen','label'=>'Ebenen','type'=>'text'],
	  ['key'=>'Material','label'=>'Material','type'=>'text'],
	  ['key'=>'Nennhöhe mm','label'=>'Nennhöhe mm','type'=>'text'],
	  ['key'=>'Nenntiefe mm','label'=>'Nenntiefe mm','type'=>'text'],
	  ['key'=>'Nennlänge mm','label'=>'Nennlänge mm','type'=>'text'],
	  ['key'=>'Gewicht kg','label'=>'Gewicht kg','type'=>'text'],
	  ['key'=>'lichte Breite mm','label'=>'lichte Breite mm','type'=>'text'],
	  ['key'=>'Achsmaß mm','label'=>'Achsmaß mm','type'=>'text'],
	  ['key'=>'ohne MwSt. €','label'=>'ohne MwSt. €','type'=>'text'],
	  ['key'=>'mit MwSt. €','label'=>'mit MwSt. €','type'=>'text'],
	  ['key'=>'Verfügbarkeit','label'=>'Verfügbarkeit','type'=>'text'],
	],
	'free_text' => [],
  ];
}
function pv_load_fields(string $productKey): array {
  $path = pv_fields_path($productKey);
  if (!is_file($path)) return pv_default_fields_template();
  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return pv_default_fields_template();

  $raw['overrides'] = (isset($raw['overrides']) && is_array($raw['overrides'])) ? $raw['overrides'] : [];
  $raw['free_text'] = (isset($raw['free_text']) && is_array($raw['free_text'])) ? $raw['free_text'] : [];
  return $raw;
}

/* ===========================
   ✅ Produktwechsel (Session)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: admin_products.php?token=' . urlencode($token));
  exit;
}

/* ===========================
   ✅ Default-Produkt
   =========================== */
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') {
  $_SESSION['pv_product'] = 'fachbodenregal';
}
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

$repo  = new Repository($configPath, $productKey);
$cfg   = $repo->getConfig();
$admin = new Admin($configPath, $productKey);

$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;

// Flash (PRG)
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$msg = $flash !== '' ? $flash : '';
$err = '';

/* ===========================
   ✅ Actions (Produkte bearbeiten)
   =========================== */
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'save_text') {
	  $admin->saveText(
		(string)($_POST['scope'] ?? 'product'),
		(string)($_POST['article'] ?? ''),
		(string)($_POST['title'] ?? ''),
		(string)($_POST['info_html'] ?? '')
	  );
	  $_SESSION['pv_flash'] = 'Gespeichert.';
	  $redir = 'admin_products.php?token=' . urlencode($token);
	  $a = (string)($_POST['article'] ?? '');
	  if ($a !== '') $redir .= '&article=' . urlencode($a);
	  header('Location: ' . $redir . '#products_edit');
	  exit;
	}

	if ($action === 'save_overrides') {
	  $admin->saveOverrides(
		(string)($_POST['article'] ?? ''),
		(array)($_POST['ov'] ?? [])
	  );
	  $_SESSION['pv_flash'] = 'Overrides gespeichert.';
	  header('Location: admin_products.php?token=' . urlencode($token) . '&article=' . urlencode((string)($_POST['article'] ?? '')) . '#products_edit');
	  exit;
	}

	if ($action === 'upload_images') {
	  $admin->uploadImages((string)($_POST['article'] ?? ''), $_FILES['images'] ?? null);
	  $_SESSION['pv_flash'] = 'Bilder hochgeladen.';
	  header('Location: admin_products.php?token=' . urlencode($token) . '&article=' . urlencode((string)($_POST['article'] ?? '')) . '#products_edit');
	  exit;
	}

	if ($action === 'delete_image') {
	  $admin->deleteImage((string)($_POST['article'] ?? ''), (string)($_POST['img'] ?? ''));
	  $_SESSION['pv_flash'] = 'Bild gelöscht.';
	  header('Location: admin_products.php?token=' . urlencode($token) . '&article=' . urlencode((string)($_POST['article'] ?? '')) . '#products_edit');
	  exit;
	}

	if ($action === 'delete_all_images') {
	  $art = (string)($_POST['article'] ?? '');
	  if ($art === '') throw new RuntimeException('Artikel fehlt.');

	  $d = $repo->detail($art);
	  $imgs = $d['content_article']['images'] ?? [];

	  if (!is_array($imgs) || empty($imgs)) {
		$_SESSION['pv_flash'] = 'Keine Bilder vorhanden.';
	  } else {
		foreach ($imgs as $img) $admin->deleteImage($art, (string)$img);
		$_SESSION['pv_flash'] = 'Alle Bilder gelöscht.';
	  }
	  header('Location: admin_products.php?token=' . urlencode($token) . '&article=' . urlencode($art) . '#products_edit');
	  exit;
	}

	// ✅ Variante duplizieren (gleich wie in admin.php)
	if ($action === 'duplicate_variant') {
	  $srcPk = trim((string)($_POST['source_pk'] ?? ''));
	  $newPk = trim((string)($_POST['new_pk'] ?? ''));
	  $copyMeta = isset($_POST['copy_meta']) && (string)($_POST['copy_meta'] ?? '') === '1';
	  $copyImages = isset($_POST['copy_images']) && (string)($_POST['copy_images'] ?? '') === '1';

	  $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');
	  if ($srcPk === '') throw new RuntimeException('Quelle fehlt: Bitte eine Variante auswählen.');
	  if ($newPk === '') throw new RuntimeException('Bitte eine neue ' . $pkField . ' angeben.');
	  if ($newPk === $srcPk) throw new RuntimeException('Neue ' . $pkField . ' muss sich von der Quelle unterscheiden.');

	  $jsonPath = $productKey === ''
		? (__DIR__ . '/../' . (string)($cfg['import']['json_path'] ?? 'data/variants.json'))
		: (__DIR__ . '/../data/products/' . $productKey . '/variants.json');

	  $store = [];
	  if (is_file($jsonPath)) {
		$store = json_decode((string)file_get_contents($jsonPath), true);
	  }
	  if (!is_array($store)) $store = [];
	  if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];

	  foreach ($store['variants'] as $v) {
		if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $newPk) {
		  throw new RuntimeException('Variante existiert bereits: ' . $newPk);
		}
	  }

	  $srcVariant = null;
	  foreach ($store['variants'] as $v) {
		if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $srcPk) {
		  $srcVariant = $v;
		  break;
		}
	  }
	  if (!is_array($srcVariant)) {
		throw new RuntimeException('Quell-Variante nicht gefunden: ' . $srcPk);
	  }

	  $newVariant = $srcVariant;
	  $newVariant[$pkField] = $newPk;
	  $store['variants'][] = $newVariant;

	  $dir = dirname($jsonPath);
	  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
		throw new RuntimeException('Konnte Zielordner nicht anlegen: ' . $dir);
	  }
	  $tmp = $jsonPath . '.tmp';
	  $json = json_encode($store, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
	  if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
	  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
	  if (!@rename($tmp, $jsonPath)) {
		if (!@copy($tmp, $jsonPath)) {
		  @unlink($tmp);
		  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $jsonPath);
		}
		@unlink($tmp);
	  }

	  $copied = [];
	  if ($copyMeta || $copyImages) {
		$contentPath = $productKey === ''
		  ? (__DIR__ . '/../data/content.json')
		  : (__DIR__ . '/../data/products/' . $productKey . '/content.json');

		$c = ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]];
		if (is_file($contentPath)) {
		  $tmpc = json_decode((string)file_get_contents($contentPath), true);
		  if (is_array($tmpc)) $c = $tmpc;
		}
		$c['articles'] = $c['articles'] ?? [];

		$srcEntry = $c['articles'][$srcPk] ?? null;
		if (is_array($srcEntry)) {
		  $dstEntry = $c['articles'][$newPk] ?? [];

		  if ($copyMeta) {
			if (isset($srcEntry['info_html'])) $dstEntry['info_html'] = $srcEntry['info_html'];
			if (isset($srcEntry['overrides'])) $dstEntry['overrides'] = $srcEntry['overrides'];
			$copied[] = 'Infotext/Overrides';
		  }

		  if ($copyImages && isset($srcEntry['images']) && is_array($srcEntry['images'])) {
			$srcSafe = pv_safe_path($srcPk);
			$dstSafe = pv_safe_path($newPk);
			$newImages = [];
			foreach ($srcEntry['images'] as $img) {
			  $img = (string)$img;
			  $base = basename($img);
			  if ($productKey === '') {
				$newImages[] = $newPk . '/' . $base;
			  } else {
				$newImages[] = $productKey . '/' . $dstSafe . '/' . $base;
			  }
			}
			$dstEntry['images'] = $newImages;
			$copied[] = 'Bilder';

			$imgBase = __DIR__ . '/../images';
			$srcDir = $productKey === ''
			  ? ($imgBase . '/' . $srcSafe)
			  : ($imgBase . '/' . $productKey . '/' . $srcSafe);
			$dstDir = $productKey === ''
			  ? ($imgBase . '/' . $dstSafe)
			  : ($imgBase . '/' . $productKey . '/' . $dstSafe);

			if (is_dir($srcDir)) {
			  if (!is_dir($dstDir)) @mkdir($dstDir, 0775, true);
			  foreach (scandir($srcDir) ?: [] as $f) {
				if ($f === '.' || $f === '..') continue;
				$from = $srcDir . '/' . $f;
				$to   = $dstDir . '/' . $f;
				if (is_file($from) && !is_file($to)) {
				  @copy($from, $to);
				}
			  }
			}
		  }

		  $c['articles'][$newPk] = $dstEntry;

		  $cdir = dirname($contentPath);
		  if (!is_dir($cdir)) @mkdir($cdir, 0775, true);
		  $ctmp = $contentPath . '.tmp';
		  file_put_contents($ctmp, json_encode($c, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
		  @rename($ctmp, $contentPath);
		}
	  }

	  $_SESSION['pv_flash'] = 'Variante dupliziert: "' . $srcPk . '" → "' . $newPk . '"' . (empty($copied) ? '' : ' (mit ' . implode(', ', $copied) . ')');
	  header('Location: admin_products.php?token=' . urlencode($token) . '&article=' . urlencode($newPk) . '#products_edit');
	  exit;
	}
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

/* ===========================
   ✅ Bootstrap
   =========================== */
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));
$repo  = new Repository($configPath, $productKey);
$cfg   = $repo->getConfig();
$admin = new Admin($configPath, $productKey);

$boot = $repo->bootstrap();
$variants = $boot['variants'] ?? [];
$pk = (string)($cfg['variant']['primary_key'] ?? 'Artiklnummer');

$article = (string)($_GET['article'] ?? ($_POST['article'] ?? ''));
if ($article === '' && isset($variants[0][$pk])) $article = (string)$variants[0][$pk];

$detail = $repo->detail($article);
$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;
$shopEmail = (string)($cfg['shop']['dev_order_email'] ?? $cfg['shop']['contact_email'] ?? $cfg['shop']['email'] ?? '');

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Produkte bearbeiten</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
	.pill-toggle{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  display:inline-flex; align-items:center; gap:8px;
	}
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
	.pill-select{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  min-width:220px;
	}
	.pill-select:hover{ border-color: rgba(149,191,32,.55); }

	:root{
	  --bg:#ffffff;
	  --card:#ffffff;
	  --text:#000000;
	  --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14);
	  --accent:#95bf20;
	  --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; }
	select, input[type="text"], input[type="file"], input[type="number"], textarea{
	  background: rgba(0,0,0,.03) !important;
	  color: var(--text) !important;
	}
	textarea{ border:1px solid var(--border) !important; }
	.card{ background: var(--card) !important; }
	.btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
	.btn:hover{ border-color: rgba(149,191,32,.55) !important; }

	body.layout-pro{
	  --bg:#0b0e14;
	  --card:#121827;
	  --text:#e8f0ff;
	  --muted:#9ab0c7;
	  --border:rgba(255,255,255,.10);
	  --accent:#76a7ff;
	  --shadow:0 10px 30px rgba(0,0,0,.35);
	  background:
		radial-gradient(1200px 600px at 30% 0%, rgba(118,167,255,.18), transparent 55%),
		radial-gradient(1200px 600px at 70% 0%, rgba(118,255,214,.08), transparent 55%),
		var(--bg) !important;
	}
	body.layout-pro select,
	body.layout-pro input[type="text"],
	body.layout-pro input[type="file"],
	body.layout-pro input[type="number"],
	body.layout-pro textarea{
	  background: rgba(0,0,0,.25) !important;
	  color: var(--text) !important;
	}
	body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
	body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
	body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }
	body.layout-pro .pill-toggle,
	body.layout-pro .pill-select{
	  background: rgba(0,0,0,.18);
	  color: var(--text);
	}
	body.layout-pro .pill-toggle:hover,
	body.layout-pro .pill-select:hover{
	  border-color: rgba(118,167,255,.35);
	}
	.h-sub, .small{ color: var(--muted) !important; }

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
	.grid{ display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
	@media (max-width: 980px){ .grid{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Produkte bearbeiten</div>
		<div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong></div>
	  </div>

	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<!-- Produkt-Umschalter -->
		<form method="post" style="margin:0">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="set_product">
		  <select class="pill-select" name="product" onchange="this.form.submit()">
			<?php foreach ($products as $k => $label): ?>
			  <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>>
				<?= h($label) ?>
			  </option>
			<?php endforeach; ?>
		  </select>
		</form>

		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="admin.php?token=<?= urlencode($token) ?>">Zurück zum Admin</a>
		<a class="btn" href="index.php" target="_blank" rel="noopener">Frontend</a>
	  </div>
	</div>

	<?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
	<?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
	</div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

	<!-- =========================================================
		 ✅ Produkte bearbeiten
		 ========================================================= -->
	<div class="card" id="products_edit" style="border-radius:14px; margin-bottom:12px;">
	  <div class="card-h">
		<div class="card-title">Produkte bearbeiten</div>
		<div class="small">Variante wählen/duplizieren · Texte · Bilder · Overrides</div>
	  </div>
	  <div class="card-b">

		<!-- ✅ Variante wählen -->
		<div class="card" style="border-radius:14px; margin-bottom:12px;">
		  <div class="card-h">
			<div class="card-title">Variante wählen</div>
			<div class="small"><?= h($pk) ?></div>
		  </div>
		  <div class="card-b">
			<?php if (!$variants): ?>
			  <div class="small">Noch keine Varianten vorhanden. Bitte zuerst Import ausführen.</div>
			<?php else: ?>
			  <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<div style="min-width:260px">
				  <label>Artikelnummer</label>
				  <select name="article" onchange="this.form.submit()">
					<?php foreach ($variants as $v):
					  $val = (string)($v[$pk] ?? '');
					  if ($val==='') continue;
					  $sel = $val===$article ? 'selected' : '';
					?>
					  <option value="<?= h($val) ?>" <?= $sel ?>><?= h($val) ?></option>
					<?php endforeach; ?>
				  </select>
				</div>
				<noscript><button class="btn" type="submit">Laden</button></noscript>
			  </form>

			  <div style="height:12px"></div>

			  <!-- ✅ Variante duplizieren -->
			  <div class="small" style="font-weight:800">Variante duplizieren</div>
			  <form method="post" autocomplete="off" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:6px">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="duplicate_variant">
				<input type="hidden" name="source_pk" value="<?= h($article) ?>">

				<div style="min-width:260px">
				  <label>Neue <?= h($pk) ?></label>
				  <input name="new_pk" required placeholder="z.B. <?= h($article) ?>_Kopie">
				</div>

				<label style="display:flex; gap:8px; align-items:center; margin:0">
				  <input type="checkbox" name="copy_meta" value="1" checked>
				  <span class="small">Infotext/Overrides mitnehmen</span>
				</label>

				<label style="display:flex; gap:8px; align-items:center; margin:0">
				  <input type="checkbox" name="copy_images" value="1" checked>
				  <span class="small">Bilder mitnehmen</span>
				</label>

				<button class="btn" type="submit">Duplizieren</button>
			  </form>
			<?php endif; ?>
		  </div>
		</div>

		<!-- ✅ Infotexte -->
		<div class="grid" style="margin-bottom:12px;">
		  <div class="card" style="border-radius:14px;">
			<div class="card-h"><div class="card-title">Infotext (global)</div></div>
			<div class="card-b">
			  <form method="post">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="save_text">
				<input type="hidden" name="scope" value="product">
				<label>Titel (optional)</label>
				<input name="title" value="<?= h($boot['content']['product']['title'] ?? '') ?>">
				<div style="height:10px"></div>
				<label>Info HTML</label>
				<textarea name="info_html" style="width:100%; min-height:180px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($boot['content']['product']['info_html'] ?? '') ?></textarea>
				<div style="height:10px"></div>
				<button class="btn" type="submit">Speichern</button>
			  </form>
			</div>
		  </div>

		  <div class="card" style="border-radius:14px;">
			<div class="card-h"><div class="card-title">Infotext (Variante)</div></div>
			<div class="card-b">
			  <form method="post">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="save_text">
				<input type="hidden" name="scope" value="article">
				<input type="hidden" name="article" value="<?= h($article) ?>">
				<label>Info HTML (überschreibt global)</label>
				<textarea name="info_html" style="width:100%; min-height:180px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($detail['content_article']['info_html'] ?? '') ?></textarea>
				<div style="height:10px"></div>
				<button class="btn" type="submit">Speichern</button>
			  </form>
			</div>
		  </div>
		</div>

		<!-- ✅ Bilder + Overrides -->
		<div class="grid">
		  <div class="card" style="border-radius:14px;">
			<div class="card-h"><div class="card-title">Bilder</div></div>
			<div class="card-b">
			  <form method="post" enctype="multipart/form-data">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="upload_images">
				<input type="hidden" name="article" value="<?= h($article) ?>">
				<label>Bilder auswählen (jpg/png/webp)</label>
				<input type="file" name="images[]" multiple accept=".jpg,.jpeg,.png,.webp" style="color:var(--muted)">
				<div style="height:10px"></div>
				<button class="btn" type="submit">Hochladen</button>
			  </form>

			  <?php $hasImages = !empty($detail['content_article']['images'] ?? []); ?>
			  <form method="post" style="margin-top:10px" onsubmit="return confirm('Wirklich ALLE Bilder dieser Variante löschen?')">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="delete_all_images">
				<input type="hidden" name="article" value="<?= h($article) ?>">
				<button class="btn" type="submit" style="height:32px" <?= $hasImages ? '' : 'disabled' ?>>Alle löschen</button>
			  </form>

			  <div style="height:12px"></div>
			  <div class="small">Aktuelle Bilder:</div>
			  <div class="thumbs">
				<?php foreach (($detail['content_article']['images'] ?? []) as $img): ?>
				  <div>
					<div class="thumb" style="width:110px; height:80px"><img src="../images/<?= h($img) ?>" alt=""></div>
					<form method="post" style="margin-top:6px">
					  <input type="hidden" name="token" value="<?= h($token) ?>">
					  <input type="hidden" name="action" value="delete_image">
					  <input type="hidden" name="article" value="<?= h($article) ?>">
					  <input type="hidden" name="img" value="<?= h($img) ?>">
					  <button class="btn" type="submit" style="height:32px">Löschen</button>
					</form>
				  </div>
				<?php endforeach; ?>
			  </div>
			</div>
		  </div>

		  <div class="card" style="border-radius:14px;">
			<div class="card-h"><div class="card-title">Texte/Felder korrigieren (Overrides)</div></div>
			<div class="card-b">
			  <div class="small">
				Overrides werden gespeichert in
				<code><?= $productKey === '' ? 'data/content.json' : ('data/products/' . h($productKey) . '/content.json') ?></code>
			  </div>
			  <div style="height:10px"></div>

			  <form method="post">
				<input type="hidden" name="token" value="<?= h($token) ?>">
				<input type="hidden" name="action" value="save_overrides">
				<input type="hidden" name="article" value="<?= h($article) ?>">

				<?php
				  $fieldsDef2 = pv_load_fields($productKey);
				  $ov = $detail['content_article']['overrides'] ?? [];
				  $overrideFields = $fieldsDef2['overrides'] ?? [];
				  $freeTextFields = $fieldsDef2['free_text'] ?? [];
				  if (empty($overrideFields) && empty($freeTextFields)) {
					$fieldsDef2 = pv_default_fields_template();
					$overrideFields = $fieldsDef2['overrides'];
					$freeTextFields = $fieldsDef2['free_text'];
				  }
				?>

				<?php foreach ($overrideFields as $f):
				  if (!is_array($f)) continue;
				  $k = (string)($f['key'] ?? '');
				  if ($k === '') continue;
				  $label = (string)($f['label'] ?? $k);
				  $type  = strtolower((string)($f['type'] ?? 'text'));
				  $cur = $ov[$k] ?? ($detail['article'][$k] ?? '');
				?>
				  <div style="margin-bottom:10px">
					<label><?= h($label) ?></label>
					<?php if ($type === 'textarea'): ?>
					  <textarea name="ov[<?= h($k) ?>]" style="width:100%; min-height:110px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($cur) ?></textarea>
					<?php else: ?>
					  <input name="ov[<?= h($k) ?>]" value="<?= h($cur) ?>">
					<?php endif; ?>
				  </div>
				<?php endforeach; ?>

				<?php if (!empty($freeTextFields)): ?>
				  <div class="small" style="font-weight:800; margin:14px 0 8px;">Freitextfelder</div>
				  <?php foreach ($freeTextFields as $f):
					if (!is_array($f)) continue;
					$k = (string)($f['key'] ?? '');
					if ($k === '') continue;
					$label = (string)($f['label'] ?? $k);
					$type  = strtolower((string)($f['type'] ?? 'textarea'));
					$cur = $ov[$k] ?? '';
				  ?>
					<div style="margin-bottom:10px">
					  <label><?= h($label) ?></label>
					  <?php if ($type === 'text'): ?>
						<input name="ov[<?= h($k) ?>]" value="<?= h($cur) ?>">
					  <?php else: ?>
						<textarea name="ov[<?= h($k) ?>]" style="width:100%; min-height:140px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($cur) ?></textarea>
					  <?php endif; ?>
					</div>
				  <?php endforeach; ?>
				<?php endif; ?>

				<button class="btn" type="submit">Overrides speichern</button>
			  </form>
			</div>
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
		if(btn) btn.textContent = 'Layout: ' + (isPro ? 'Hell' : 'Dunkel');
	  }
	  function apply(mode){
		document.body.classList.toggle('layout-pro', mode === 'pro');
		setButtonLabel();
	  }
	  const saved = localStorage.getItem(KEY);
	  apply(saved === 'pro' ? 'pro' : 'light');

	  if(btn){
		btn.addEventListener('click', function(){
		  const next = document.body.classList.contains('layout-pro') ? 'light' : 'pro';
		  localStorage.setItem(KEY, next);
		  apply(next);
		});
	  }
	})();
  </script>
</body>
</html>
