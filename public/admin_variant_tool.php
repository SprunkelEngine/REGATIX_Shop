<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_bootstrap.php';

/* ===========================
   Hilfsfunktionen für Varianten-PK
   =========================== */
if (!function_exists('pv_variant_pk_candidates')) {
  function pv_variant_pk_candidates(array $cfg): array {
	$primary = trim((string)($cfg['variant']['primary_key'] ?? ''));
	$candidates = [
	  $primary,
	  'Artikelnummer',
	  'Artikelnummer',
	  'Artiklnummer',
	  'article',
	  'sku',
	  'SKU',
	];

	$out = [];
	foreach ($candidates as $c) {
	  $c = trim((string)$c);
	  if ($c !== '' && !in_array($c, $out, true)) {
		$out[] = $c;
	  }
	}
	return $out;
  }
}

if (!function_exists('pv_variant_pk_value')) {
  function pv_variant_pk_value(array $variant, array $pkCandidates): string {
	foreach ($pkCandidates as $key) {
	  if (array_key_exists($key, $variant)) {
		$val = trim((string)$variant[$key]);
		if ($val !== '') return $val;
	  }
	}
	return '';
  }
}

$pkCandidates = pv_variant_pk_candidates($cfg);
$pk = $pkCandidates[0] ?? 'Artikelnummer';

/* ===========================
   Variantenliste normalisieren
   =========================== */
$normalizedVariants = [];
$variantValues = [];

if (!empty($variants) && is_array($variants)) {
  foreach ($variants as $v) {
	if (!is_array($v)) continue;
	$val = pv_variant_pk_value($v, $pkCandidates);
	if ($val === '') continue;

	$v['__pv_pk_value'] = $val;
	$normalizedVariants[] = $v;
	$variantValues[] = $val;
  }
}

$variants = $normalizedVariants;

/* ===========================
   Aktive Variante sicher bestimmen
   =========================== */
$article = trim((string)($article ?? ($_GET['article'] ?? '')));
if ($article === '' || !in_array($article, $variantValues, true)) {
  $article = $variantValues[0] ?? '';
}

/* ===========================
   Detaildaten bei Bedarf neu laden
   =========================== */
if ($article !== '' && method_exists($repo, 'detail')) {
  try {
	$detail = $repo->detail($article);
  } catch (Throwable $e) {
	// Falls detail() fehlschlägt, nicht hart abbrechen
	$detail = $detail ?? ['article' => [], 'content_article' => []];
  }
}

/* ===========================
   ✅ POST Actions (nur Varianten-Tool Bereich)
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
	  $a = (string)($_POST['article'] ?? '');
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . ($a !== '' ? '&article=' . urlencode($a) : ''));
	  exit;
	}

	if ($action === 'save_overrides') {
	  $a = (string)($_POST['article'] ?? '');
	  $admin->saveOverrides($a, (array)($_POST['ov'] ?? []));

	  $_SESSION['pv_flash'] = 'Overrides gespeichert.';
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . ($a !== '' ? '&article=' . urlencode($a) : ''));
	  exit;
	}

	if ($action === 'upload_images') {
	  $a = (string)($_POST['article'] ?? '');
	  $admin->uploadImages($a, $_FILES['images'] ?? null);

	  $_SESSION['pv_flash'] = 'Bilder hochgeladen.';
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . ($a !== '' ? '&article=' . urlencode($a) : ''));
	  exit;
	}

	if ($action === 'delete_image') {
	  $a = (string)($_POST['article'] ?? '');
	  $img = (string)($_POST['img'] ?? '');
	  $admin->deleteImage($a, $img);

	  $_SESSION['pv_flash'] = 'Bild gelöscht.';
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . ($a !== '' ? '&article=' . urlencode($a) : ''));
	  exit;
	}

	if ($action === 'delete_all_images') {
	  $a = (string)($_POST['article'] ?? '');
	  if ($a === '') throw new RuntimeException('Artikel fehlt.');

	  $d = $repo->detail($a);
	  $imgs = $d['content_article']['images'] ?? [];
	  if (is_array($imgs)) {
		foreach ($imgs as $img) $admin->deleteImage($a, (string)$img);
	  }

	  $_SESSION['pv_flash'] = 'Alle Bilder gelöscht.';
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . '&article=' . urlencode($a));
	  exit;
	}

	if ($action === 'duplicate_variant') {
	  $srcPk = trim((string)($_POST['source_pk'] ?? ''));
	  $newPk = trim((string)($_POST['new_pk'] ?? ''));
	  $copyMeta = isset($_POST['copy_meta']) && (string)($_POST['copy_meta'] ?? '') === '1';
	  $copyImages = isset($_POST['copy_images']) && (string)($_POST['copy_images'] ?? '') === '1';

	  $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artikelnummer');
	  if ($pkField === '') $pkField = 'Artikelnummer';

	  if ($srcPk === '') throw new RuntimeException('Quelle fehlt: Bitte eine Variante auswählen.');
	  if ($newPk === '') throw new RuntimeException('Bitte eine neue ' . $pkField . ' angeben.');
	  if ($newPk === $srcPk) throw new RuntimeException('Neue ' . $pkField . ' muss sich von der Quelle unterscheiden.');

	  $jsonPath = pv_variants_path_for_product($cfg, $productKey);
	  $store = pv_load_json_array($jsonPath, []);
	  if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];

	  foreach ($store['variants'] as $v) {
		if (is_array($v) && pv_variant_pk_value($v, $pkCandidates) === $newPk) {
		  throw new RuntimeException('Variante existiert bereits: ' . $newPk);
		}
	  }

	  $srcVariant = null;
	  foreach ($store['variants'] as $v) {
		if (is_array($v) && pv_variant_pk_value($v, $pkCandidates) === $srcPk) {
		  $srcVariant = $v;
		  break;
		}
	  }
	  if (!is_array($srcVariant)) throw new RuntimeException('Quell-Variante nicht gefunden: ' . $srcPk);

	  $targetKey = '';
	  foreach ($pkCandidates as $candidate) {
		if (array_key_exists($candidate, $srcVariant)) {
		  $targetKey = $candidate;
		  break;
		}
	  }
	  if ($targetKey === '') $targetKey = $pkField;

	  $newVariant = $srcVariant;
	  $newVariant[$targetKey] = $newPk;
	  $store['variants'][] = $newVariant;

	  pv_atomic_write_json($jsonPath, $store);

	  $copied = [];
	  if ($copyMeta || $copyImages) {
		$contentPath = pv_content_path_for_product($productKey);
		$c = pv_load_json_array($contentPath, ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]]);
		if (!isset($c['articles']) || !is_array($c['articles'])) $c['articles'] = [];

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
			  $base = basename((string)$img);
			  if ($productKey === '') $newImages[] = $newPk . '/' . $base;
			  else $newImages[] = $productKey . '/' . $dstSafe . '/' . $base;
			}
			$dstEntry['images'] = $newImages;
			$copied[] = 'Bilder';

			$imgBase = __DIR__ . '/../images';
			$srcDir = $productKey === '' ? ($imgBase . '/' . $srcSafe) : ($imgBase . '/' . $productKey . '/' . $srcSafe);
			$dstDir = $productKey === '' ? ($imgBase . '/' . $dstSafe) : ($imgBase . '/' . $productKey . '/' . $dstSafe);

			if (is_dir($srcDir)) {
			  if (!is_dir($dstDir)) @mkdir($dstDir, 0775, true);
			  foreach (scandir($srcDir) ?: [] as $f) {
				if ($f === '.' || $f === '..') continue;
				$from = $srcDir . '/' . $f;
				$to   = $dstDir . '/' . $f;
				if (is_file($from) && !is_file($to)) @copy($from, $to);
			  }
			}
		  }

		  $c['articles'][$newPk] = $dstEntry;
		  pv_atomic_write_json($contentPath, $c);
		}
	  }

	  $_SESSION['pv_flash'] =
		'Variante dupliziert: "' . $srcPk . '" → "' . $newPk . '"' .
		(empty($copied) ? '' : ' (mit ' . implode(', ', $copied) . ')');

	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . '&article=' . urlencode($newPk));
	  exit;
	}

	if ($action === 'delete_variant') {
	  $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artikelnummer');
	  if ($pkField === '') $pkField = 'Artikelnummer';

	  $pkToDelete = trim((string)($_POST['pk'] ?? ''));
	  $deleteContent = isset($_POST['delete_content']) && (string)($_POST['delete_content'] ?? '') === '1';
	  $deleteImages  = isset($_POST['delete_images']) && (string)($_POST['delete_images'] ?? '') === '1';

	  if ($pkToDelete === '') throw new RuntimeException('Artikelnummer fehlt.');

	  $variantsPath = pv_variants_path_for_product($cfg, $productKey);
	  $store = pv_load_json_array($variantsPath, []);
	  if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];

	  $before = count($store['variants']);
	  $store['variants'] = array_values(array_filter($store['variants'], function($v) use ($pkToDelete, $pkCandidates){
		return !(is_array($v) && pv_variant_pk_value($v, $pkCandidates) === $pkToDelete);
	  }));
	  $after = count($store['variants']);
	  if ($before === $after) throw new RuntimeException('Variante nicht gefunden: ' . $pkToDelete);

	  pv_atomic_write_json($variantsPath, $store);

	  if ($deleteContent) {
		$contentPath = pv_content_path_for_product($productKey);
		$c = pv_load_json_array($contentPath, ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]]);
		if (!isset($c['articles']) || !is_array($c['articles'])) $c['articles'] = [];
		if (isset($c['articles'][$pkToDelete])) {
		  unset($c['articles'][$pkToDelete]);
		  pv_atomic_write_json($contentPath, $c);
		}
	  }

	  if ($deleteImages) {
		$imgBase = __DIR__ . '/../images';
		$safe = pv_safe_path($pkToDelete);
		$dir = $productKey === '' ? ($imgBase . '/' . $safe) : ($imgBase . '/' . $productKey . '/' . $safe);
		pv_delete_dir_recursive($dir);

		$contentPath = pv_content_path_for_product($productKey);
		$c = pv_load_json_array($contentPath, ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]]);
		if (isset($c['articles'][$pkToDelete]) && is_array($c['articles'][$pkToDelete])) {
		  unset($c['articles'][$pkToDelete]['images']);
		  pv_atomic_write_json($contentPath, $c);
		}
	  }

	  $_SESSION['pv_flash'] = 'Variante gelöscht: ' . $pkToDelete;
	  header('Location: admin_variant_tool.php?token=' . urlencode($token));
	  exit;
	}

	if ($action === 'rename_variant_pk') {
	  $pkField = (string)($cfg['variant']['primary_key'] ?? 'Artikelnummer');
	  if ($pkField === '') $pkField = 'Artikelnummer';

	  $oldPk = trim((string)($_POST['old_pk'] ?? ''));
	  $newPk = trim((string)($_POST['new_pk'] ?? ''));

	  $moveContent = isset($_POST['move_content']) && (string)($_POST['move_content'] ?? '') === '1';
	  $moveImages  = isset($_POST['move_images']) && (string)($_POST['move_images'] ?? '') === '1';

	  if ($oldPk === '') throw new RuntimeException('Alte Artikelnummer fehlt.');
	  if ($newPk === '') throw new RuntimeException('Neue Artikelnummer fehlt.');
	  if ($newPk === $oldPk) throw new RuntimeException('Neue Artikelnummer ist identisch.');

	  $variantsPath = pv_variants_path_for_product($cfg, $productKey);
	  $store = pv_load_json_array($variantsPath, []);
	  if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];

	  foreach ($store['variants'] as $v) {
		if (is_array($v) && pv_variant_pk_value($v, $pkCandidates) === $newPk) {
		  throw new RuntimeException('Ziel-Artikelnummer existiert bereits: ' . $newPk);
		}
	  }

	  $found = false;
	  foreach ($store['variants'] as &$v) {
		if (is_array($v) && pv_variant_pk_value($v, $pkCandidates) === $oldPk) {
		  $realKey = '';
		  foreach ($pkCandidates as $candidate) {
			if (array_key_exists($candidate, $v)) {
			  $realKey = $candidate;
			  break;
			}
		  }
		  if ($realKey === '') $realKey = $pkField;

		  $v[$realKey] = $newPk;
		  $found = true;
		  break;
		}
	  }
	  unset($v);

	  if (!$found) throw new RuntimeException('Quelle nicht gefunden: ' . $oldPk);

	  pv_atomic_write_json($variantsPath, $store);

	  if ($moveContent) {
		$contentPath = pv_content_path_for_product($productKey);
		$c = pv_load_json_array($contentPath, ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]]);
		if (!isset($c['articles']) || !is_array($c['articles'])) $c['articles'] = [];

		if (isset($c['articles'][$newPk])) throw new RuntimeException('Content-Ziel existiert bereits in content.json: ' . $newPk);

		if (isset($c['articles'][$oldPk])) {
		  $c['articles'][$newPk] = $c['articles'][$oldPk];
		  unset($c['articles'][$oldPk]);
		  pv_atomic_write_json($contentPath, $c);
		}
	  }

	  if ($moveImages) {
		$imgBase = __DIR__ . '/../images';
		$oldSafe = pv_safe_path($oldPk);
		$newSafe = pv_safe_path($newPk);

		$oldDir = $productKey === '' ? ($imgBase . '/' . $oldSafe) : ($imgBase . '/' . $productKey . '/' . $oldSafe);
		$newDir = $productKey === '' ? ($imgBase . '/' . $newSafe) : ($imgBase . '/' . $productKey . '/' . $newSafe);

		if (is_dir($oldDir)) {
		  $parent = dirname($newDir);
		  if (!is_dir($parent)) @mkdir($parent, 0775, true);
		  if (!@rename($oldDir, $newDir)) {
			@mkdir($newDir, 0775, true);
			foreach (scandir($oldDir) ?: [] as $f) {
			  if ($f === '.' || $f === '..') continue;
			  $from = $oldDir . '/' . $f;
			  $to   = $newDir . '/' . $f;
			  if (is_file($from)) @copy($from, $to);
			}
			pv_delete_dir_recursive($oldDir);
		  }
		}

		$contentPath = pv_content_path_for_product($productKey);
		$c = pv_load_json_array($contentPath, ['product'=>['title'=>'','info_html'=>''], 'articles'=>[]]);

		if (isset($c['articles'][$newPk]) && is_array($c['articles'][$newPk]) &&
			isset($c['articles'][$newPk]['images']) && is_array($c['articles'][$newPk]['images'])) {

		  $updated = [];
		  foreach ($c['articles'][$newPk]['images'] as $img) {
			$base = basename((string)$img);
			if ($productKey === '') $updated[] = $newPk . '/' . $base;
			else $updated[] = $productKey . '/' . $newSafe . '/' . $base;
		  }
		  $c['articles'][$newPk]['images'] = $updated;
		  pv_atomic_write_json($contentPath, $c);
		}
	  }

	  $_SESSION['pv_flash'] = 'Artikelnummer geändert: "' . $oldPk . '" → "' . $newPk . '"';
	  header('Location: admin_variant_tool.php?token=' . urlencode($token) . '&article=' . urlencode($newPk));
	  exit;
	}
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Varianten-Tool</title>
  <link rel="stylesheet" href="assets/styles.css">

  <style>
	.pill-toggle{
	  height:38px;
	  padding:0 14px;
	  border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  display:inline-flex;
	  align-items:center;
	  gap:8px;
	}
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }

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
	body.layout-pro .pill-toggle{
	  background: rgba(0,0,0,.18);
	  color: var(--text);
	}
	body.layout-pro .pill-toggle:hover{
	  border-color: rgba(118,167,255,.35);
	}

	.h-sub, .small{ color: var(--muted) !important; }

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Varianten-Tool</div>
		<div class="h-sub">Produkt: <strong><?= h($productKey) ?></strong></div>
	  </div>
	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Dunkel</button>
		<a class="btn" href="admin.php?token=<?= urlencode($token) ?>">Zurück</a>
	  </div>
	</div>

	<?php if (!empty($msg)): ?>
	  <div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div>
	  <div style="height:10px"></div>
	<?php endif; ?>

	<?php if (!empty($err)): ?>
	  <div class="warn">
		<div class="warn-top">
		  <div class="tri" aria-hidden="true">
			<svg viewBox="0 0 24 24" width="22" height="22" fill="none">
			  <path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/>
			</svg>
		  </div>
		  <div>
			<h4>Fehler</h4>
			<div class="small"><?= h($err) ?></div>
		  </div>
		</div>
	  </div>
	  <div style="height:10px"></div>
	<?php endif; ?>

	<div class="card">
	  <div class="card-h">
		<div class="card-title">Variante wählen</div>
		<div class="small"><?= h($pk) ?></div>
	  </div>
	  <div class="card-b">
		<?php if (!$variants): ?>
		  <div class="small">Noch keine Varianten vorhanden. Bitte zuerst Import ausführen.</div>
		<?php else: ?>
		  <form method="get" id="pvVariantForm" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
			<input type="hidden" name="token" value="<?= h($token) ?>">

			<div style="min-width:260px">
			  <label>Suche</label>
			  <input id="pvVariantSearch" type="text" inputmode="search" autocomplete="off"
					 placeholder="z.B. 12345 oder _Kopie" style="height:38px">
			  <div class="small" style="margin-top:6px">Tippen zum Filtern · Enter lädt den ersten Treffer</div>
			</div>

			<div style="min-width:260px">
			  <label>Artikelnummer</label>
			  <select id="pvVariantSelect" name="article" onchange="this.form.submit()">
				<?php foreach ($variants as $v):
				  $val = (string)($v['__pv_pk_value'] ?? '');
				  if ($val === '') continue;
				  $sel = ($val === $article) ? 'selected' : '';
				?>
				  <option value="<?= h($val) ?>" <?= $sel ?>><?= h($val) ?></option>
				<?php endforeach; ?>
			  </select>
			</div>

			<noscript><button class="btn" type="submit">Laden</button></noscript>
		  </form>

		  <div style="height:12px"></div>

		  <div class="small">Variante duplizieren:</div>
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

		  <div style="height:12px"></div>

		  <div class="small">Variante löschen:</div>
		  <form method="post" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:6px"
				onsubmit="return confirm('Wirklich diese Variante löschen?\n\n<?= h($pk) ?>: <?= h($article) ?>');">
			<input type="hidden" name="token" value="<?= h($token) ?>">
			<input type="hidden" name="action" value="delete_variant">
			<input type="hidden" name="pk" value="<?= h($article) ?>">

			<label style="display:flex; gap:8px; align-items:center; margin:0">
			  <input type="checkbox" name="delete_content" value="1" checked>
			  <span class="small">Content-Eintrag löschen</span>
			</label>

			<label style="display:flex; gap:8px; align-items:center; margin:0">
			  <input type="checkbox" name="delete_images" value="1" checked>
			  <span class="small">Bilder löschen</span>
			</label>

			<button class="btn" type="submit" style="border-color:rgba(255,77,77,.45)">Variante löschen</button>
		  </form>

		  <div style="height:12px"></div>

		  <div class="small">Artikelnummer ändern:</div>
		  <form method="post" autocomplete="off" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:6px"
				onsubmit="return confirm('Artikelnummer wirklich ändern?\n\n<?= h($pk) ?>: <?= h($article) ?> → ' + (this.querySelector('[name=new_pk]').value || ''));">

			<input type="hidden" name="token" value="<?= h($token) ?>">
			<input type="hidden" name="action" value="rename_variant_pk">
			<input type="hidden" name="old_pk" value="<?= h($article) ?>">

			<div style="min-width:260px">
			  <label>Neue <?= h($pk) ?></label>
			  <input name="new_pk" required placeholder="z.B. <?= h($article) ?>_NEU">
			</div>

			<label style="display:flex; gap:8px; align-items:center; margin:0">
			  <input type="checkbox" name="move_content" value="1" checked>
			  <span class="small">Content umhängen</span>
			</label>

			<label style="display:flex; gap:8px; align-items:center; margin:0">
			  <input type="checkbox" name="move_images" value="1" checked>
			  <span class="small">Bilder umziehen</span>
			</label>

			<button class="btn" type="submit">Artikelnummer ändern</button>
		  </form>
		<?php endif; ?>
	  </div>
	</div>

	<div style="height:14px"></div>

	<div class="grid">
	  <div class="card">
		<div class="card-h"><div class="card-title">Infotext (global)</div></div>
		<div class="card-b">
		  <form method="post">
			<input type="hidden" name="token" value="<?= h($token) ?>">
			<input type="hidden" name="action" value="save_text">
			<input type="hidden" name="scope" value="product">
			<input type="hidden" name="article" value="<?= h($article) ?>">

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

	  <div class="card">
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

	<div style="height:14px"></div>

	<div class="grid">
	  <div class="card">
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
				<div class="thumb" style="width:110px; height:80px">
				  <img src="../images/<?= h($img) ?>" alt="">
				</div>
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

	  <div class="card">
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

  <script>
  (function(){
	const input = document.getElementById('pvVariantSearch');
	const select = document.getElementById('pvVariantSelect');
	const form = document.getElementById('pvVariantForm');
	if (!input || !select || !form) return;

	const original = Array.from(select.options).map(o => ({
	  value: o.value,
	  text: o.text,
	  selected: o.selected
	}));

	function rebuild(filter){
	  const q = (filter || '').trim().toLowerCase();
	  const current = select.value;

	  select.innerHTML = '';

	  let firstValue = '';
	  for (const opt of original) {
		if (q !== '' && !opt.text.toLowerCase().includes(q)) continue;

		const o = document.createElement('option');
		o.value = opt.value;
		o.textContent = opt.text;

		if (opt.value === current) o.selected = true;
		select.appendChild(o);

		if (!firstValue) firstValue = opt.value;
	  }

	  if (select.options.length > 0 && !Array.from(select.options).some(o => o.selected)) {
		select.value = firstValue;
	  }
	}

	input.addEventListener('input', () => rebuild(input.value));

	input.addEventListener('keydown', (e) => {
	  if (e.key === 'Enter') {
		e.preventDefault();
		if (select.options.length > 0) form.submit();
	  }
	  if (e.key === 'Escape') {
		input.value = '';
		rebuild('');
	  }
	});

	rebuild('');
  })();
  </script>
</body>
</html>