<?php
declare(strict_types=1);

session_start();

/* ===========================
   ✅ No-Cache (damit Vorschau/Wechsel immer aktuell ist)
   =========================== */
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: no-cache');
header('Expires: 0');

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)@file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===========================
   ✅ Token Check wie in admin.php
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   ✅ Key helper
   =========================== */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

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
		}
		if (isset($v0['Produktart']) && stripos((string)$v0['Produktart'], 'Eckregal') !== false) {
		  $isEckregal = true;
		}
	  }
	}
	if ($isEckregal) continue;

	$out[$key] = $label;
  }
  ksort($out);
  return $out;
}

function pv_variants_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/variants.json';
}

function pv_load_variants_store(string $path): array {
  if (!is_file($path)) return ['vat_rate'=>0.19, 'variants'=>[]];
  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return ['vat_rate'=>0.19, 'variants'=>[]];

  if (isset($raw['variants']) && is_array($raw['variants'])) return $raw;
  // falls Datei nur Liste ist
  if (array_is_list($raw)) return ['vat_rate'=>0.19, 'variants'=>$raw];

  return ['vat_rate'=>0.19, 'variants'=>[]];
}

function pv_atomic_write_json(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }
  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('JSON konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  if (!@rename($tmp, $path)) {
	if (!@copy($tmp, $path)) {
	  @unlink($tmp);
	  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	}
	@unlink($tmp);
  }
}

/* ===========================
   ✅ Money parsing/formatting
   - akzeptiert "102,35 €", "69.50", "0,00 €", "121,80 €"
   =========================== */
function pv_parse_money_like(string $s): ?float {
  $s = trim($s);
  if ($s === '') return null;

  // Währung + Spaces entfernen
  $s2 = str_replace(["€", "EUR", "\xC2\xA0", " "], "", $s);

  // wenn beide vorkommen, letzte ist Dezimaltrenner
  $hasComma = str_contains($s2, ',');
  $hasDot   = str_contains($s2, '.');

  if ($hasComma && $hasDot) {
	$lastComma = strrpos($s2, ',');
	$lastDot   = strrpos($s2, '.');
	if ($lastComma !== false && $lastDot !== false) {
	  if ($lastComma > $lastDot) {
		// comma decimals: remove dots as thousands
		$s2 = str_replace('.', '', $s2);
		$s2 = str_replace(',', '.', $s2);
	  } else {
		// dot decimals: remove commas as thousands
		$s2 = str_replace(',', '', $s2);
	  }
	}
  } elseif ($hasComma && !$hasDot) {
	$s2 = str_replace('.', '', $s2);
	$s2 = str_replace(',', '.', $s2);
  } else {
	// only dot or none -> ok, but remove commas just in case
	$s2 = str_replace(',', '', $s2);
  }

  if (!is_numeric($s2)) return null;
  return (float)$s2;
}

function pv_format_like_original(float $v, string $original): string {
  $orig = (string)$original;
  $hasEuro = str_contains($orig, '€') || stripos($orig, 'eur') !== false;

  // deutsches Format 2 Stellen
  $txt = number_format($v, 2, ',', '.');

  return $hasEuro ? ($txt . ' €') : $txt;
}

/* ===========================
   ✅ CSV lesen
   - erwartet Spaltenkopf "Artiklnummer" (oder "Artikelnummer")
   - wir nutzen CSV nur zum Filtern der SKUs (welche Varianten anpassen)
   =========================== */
function pv_read_csv_headered(string $path, string $delimiter = ';'): array {
  $rows = [];
  $fh = fopen($path, 'rb');
  if (!$fh) return $rows;

  $header = null;
  while (($line = fgetcsv($fh, 0, $delimiter)) !== false) {
	if ($header === null) {
	  $header = array_map(fn($x)=>trim((string)$x), $line);
	  continue;
	}
	$row = [];
	foreach ($header as $i => $h) {
	  if ($h === '') continue;
	  $row[$h] = isset($line[$i]) ? (string)$line[$i] : '';
	}
	$rows[] = $row;
  }
  fclose($fh);
  return $rows;
}

function pv_detect_delimiter(string $path): string {
  $sample = (string)@file_get_contents($path);
  $sample = substr($sample, 0, 4096);
  $counts = [
	';' => substr_count($sample, ';'),
	',' => substr_count($sample, ','),
	"\t" => substr_count($sample, "\t"),
  ];
  arsort($counts);
  $d = array_key_first($counts);
  return $d ?: ';';
}

/* ===========================
   ✅ State / Defaults
   =========================== */
$products = pv_products();
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') $_SESSION['pv_product'] = 'fachbodenregal';
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));
if ($productKey === '') $productKey = 'fachbodenregal';

$msg = '';
$err = '';
$preview = [];
$previewCount = 0;
$previewLimit = 80;

$defaultPriceKeys = 'ohne MwSt. €, mit MwSt. €';

/* ===========================
   ✅ Switch Product
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: admin_patch.php?token=' . urlencode($token) . '&r=' . time());
  exit;
}

/* ===========================
   ✅ Preview / Apply logic
   =========================== */
function pv_build_changes(
  array $variants,
  array $targetSkus,
  string $pkField,
  array $keysToAdjust,
  float $percent
): array {
  $changes = [];
  $changedAny = 0;

  foreach ($variants as $idx => $v) {
	if (!is_array($v)) continue;
	$sku = (string)($v[$pkField] ?? '');
	if ($sku === '') continue;

	if (!empty($targetSkus) && !isset($targetSkus[$sku])) continue;

	foreach ($keysToAdjust as $k) {
	  if (!array_key_exists($k, $v)) continue;

	  $orig = (string)$v[$k];
	  $num = pv_parse_money_like($orig);
	  if ($num === null) continue;

	  $new = $num * (1.0 + ($percent / 100.0));
	  // optional: runden auf 2 Nachkommastellen
	  $new = round($new, 2);

	  $newStr = pv_format_like_original($new, $orig);
	  if ($newStr === $orig) continue;

	  $changes[] = [
		'sku' => $sku,
		'field' => $k,
		'old' => $orig,
		'new' => $newStr,
		'variant_index' => $idx,
	  ];
	  $changedAny++;
	}
  }

  return [$changes, $changedAny];
}

function pv_apply_changes(array &$variants, array $changes): void {
  foreach ($changes as $c) {
	$i = (int)($c['variant_index'] ?? -1);
	$field = (string)($c['field'] ?? '');
	if ($i < 0 || $field === '') continue;
	if (!isset($variants[$i]) || !is_array($variants[$i])) continue;
	$variants[$i][$field] = (string)($c['new'] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['action'] ?? ''), ['preview_percent','apply_percent'], true)) {
  try {
	$action = (string)$_POST['action'];

	$pkField = trim((string)($_POST['pk_field'] ?? 'Artiklnummer'));
	if ($pkField === '') $pkField = 'Artiklnummer';

	$percentRaw = trim((string)($_POST['percent'] ?? ''));
	if ($percentRaw === '' || !is_numeric(str_replace(',', '.', $percentRaw))) {
	  throw new RuntimeException('Bitte Prozent eingeben (z.B. 5 oder -3.5).');
	}
	$percent = (float)str_replace(',', '.', $percentRaw);

	$keysRaw = trim((string)($_POST['price_keys'] ?? ''));
	if ($keysRaw === '') $keysRaw = $defaultPriceKeys;

	$keysToAdjust = array_values(array_filter(array_map('trim', explode(',', $keysRaw))));
	if (empty($keysToAdjust)) {
	  throw new RuntimeException('Preis-Felder sind leer. Bitte mindestens ein Feld angeben.');
	}

	// CSV optional -> dient als Filterliste der SKUs
	$targetSkus = [];
	if (isset($_FILES['csv']) && is_array($_FILES['csv']) && (int)($_FILES['csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
	  if ((int)$_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
		throw new RuntimeException('CSV Upload-Fehler.');
	  }
	  $tmp = (string)$_FILES['csv']['tmp_name'];
	  if (!is_file($tmp)) throw new RuntimeException('CSV nicht gefunden.');

	  $delim = pv_detect_delimiter($tmp);
	  $rows = pv_read_csv_headered($tmp, $delim);

	  $skuKey = null;
	  if (!empty($rows[0])) {
		foreach (array_keys($rows[0]) as $hk) {
		  $hkN = mb_strtolower(trim((string)$hk), 'UTF-8');
		  if ($hkN === 'artiklnummer' || $hkN === 'artikelnummer') { $skuKey = $hk; break; }
		}
	  }
	  if ($skuKey === null) {
		throw new RuntimeException('CSV braucht Spalte "Artiklnummer" (oder "Artikelnummer").');
	  }

	  foreach ($rows as $r) {
		if (!is_array($r)) continue;
		$sku = trim((string)($r[$skuKey] ?? ''));
		if ($sku !== '') $targetSkus[$sku] = true;
	  }
	  if (empty($targetSkus)) {
		throw new RuntimeException('Keine Artiklnummern in CSV gefunden.');
	  }
	}

	$vpath = pv_variants_path($productKey);
	$store = pv_load_variants_store($vpath);
	$variants = (array)($store['variants'] ?? []);

	[$changes, $count] = pv_build_changes($variants, $targetSkus, $pkField, $keysToAdjust, $percent);

	$previewCount = $count;
	$preview = array_slice($changes, 0, $previewLimit);

	if ($action === 'preview_percent') {
	  if ($count === 0) $msg = 'Vorschau: Keine Änderungen gefunden (Filter/Keys/Format prüfen).';
	  else $msg = 'Vorschau erstellt: ' . $count . ' Änderung(en).';
	}

	if ($action === 'apply_percent') {
	  if ($count === 0) throw new RuntimeException('Keine Änderungen gefunden – nichts zu schreiben.');

	  // Apply
	  pv_apply_changes($variants, $changes);
	  $store['variants'] = $variants;

	  // Backup
	  if (is_file($vpath)) {
		@copy($vpath, $vpath . '.bak_' . date('Ymd_His'));
	  }

	  pv_atomic_write_json($vpath, $store);

	  $msg = 'Update geschrieben: ' . $count . ' Änderung(en).';
	}

  } catch (Throwable $e) {
	$err = $e->getMessage();
  }
}

$currentLabel = $products[$productKey] ?? $productKey;

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Patch (Preview & % Update)</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
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
	.card{ background: var(--card) !important; border-radius:14px; }
	.h-sub, .small{ color: var(--muted) !important; }
	.btn{ background: linear-gradient(135deg, rgba(149,191,32,.18), rgba(0,0,0,.02)) !important; }
	.btn:hover{ border-color: rgba(149,191,32,.55) !important; }
	input[type="text"], input[type="number"], input[type="file"], select{
	  background: rgba(0,0,0,.03) !important; color: var(--text) !important;
	}

	.pill-select{
	  height:38px;
	  padding:0 14px;
	  border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  min-width:240px;
	}

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
	.row{ display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
	.grid2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
	@media (max-width: 900px){ .grid2{ grid-template-columns: 1fr; } }
	code{ background: rgba(0,0,0,.06); padding:2px 6px; border-radius:8px; }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Patch Tool</div>
		<div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong></div>
	  </div>

	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
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

		<a class="btn" href="admin.php?token=<?= urlencode($token) ?>" style="height:38px; line-height:36px; display:inline-block;">Zurück</a>
	  </div>
	</div>

	<?php if ($msg): ?><div class="card"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
	<?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
	</div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

	<div class="card">
	  <div class="card-h">
		<div class="card-title">Preisänderung per Prozent (mit Vorschau)</div>
		<div class="small">
		  Ändert Preisfelder in <code><?= h(str_replace(__DIR__ . '/../', '', pv_variants_path($productKey))) ?></code>.
		  Optional: CSV mit <code>Artiklnummer</code> hochladen → dann werden nur diese Artikel angepasst.
		</div>
	  </div>
	  <div class="card-b">
		<form method="post" enctype="multipart/form-data" autocomplete="off">
		  <input type="hidden" name="token" value="<?= h($token) ?>">

		  <div class="grid2">
			<div>
			  <label>Prozent (z.B. 5 oder -3.5)</label>
			  <input name="percent" required placeholder="5" value="<?= h((string)($_POST['percent'] ?? '')) ?>">
			  <div class="small" style="margin-top:6px">Beispiel: <strong>5</strong> = +5%, <strong>-3</strong> = -3%</div>
			</div>

			<div>
			  <label>PK-Feld (Standard: Artiklnummer)</label>
			  <input name="pk_field" placeholder="Artiklnummer" value="<?= h((string)($_POST['pk_field'] ?? 'Artiklnummer')) ?>">
			  <div class="small" style="margin-top:6px">Das Feld, mit dem in <code>variants.json</code> gematcht wird.</div>
			</div>
		  </div>

		  <div style="height:10px"></div>

		  <label>Preis-Felder (Komma-getrennt)</label>
		  <input name="price_keys" value="<?= h((string)($_POST['price_keys'] ?? $defaultPriceKeys)) ?>" placeholder="ohne MwSt. €, mit MwSt. €">
		  <div class="small" style="margin-top:6px">
			Beispiel: <code>ohne MwSt. €, mit MwSt. €, A ohne MwSt. €, A mit MwSt. €</code>
		  </div>

		  <div style="height:10px"></div>

		  <label>CSV (optional) – nur diese Artiklnummern anpassen</label>
		  <input type="file" name="csv" accept=".csv,text/csv">
		  <div class="small" style="margin-top:6px">Wenn keine CSV: es werden <strong>alle Varianten</strong> angepasst.</div>

		  <div style="height:12px"></div>

		  <div class="row">
			<button class="btn" type="submit" name="action" value="preview_percent" style="height:38px">Vorschau</button>
			<button class="btn" type="submit" name="action" value="apply_percent" style="height:38px"
			  onclick="return confirm('Wirklich schreiben? Änderungen werden in variants.json gespeichert (Backup wird erstellt).');">
			  Update schreiben
			</button>
		  </div>
		</form>
	  </div>
	</div>

	<div style="height:12px"></div>

	<div class="card">
	  <div class="card-h">
		<div class="card-title">Vorschau</div>
		<div class="small">
		  <?= $previewCount ? ('Gefundene Änderungen: <strong>' . (int)$previewCount . '</strong> · angezeigt: max. ' . (int)$previewLimit) : 'Noch keine Vorschau erstellt.' ?>
		</div>
	  </div>
	  <div class="card-b">
		<?php if (empty($preview)): ?>
		  <div class="small">—</div>
		<?php else: ?>
		  <table class="pv-table">
			<thead>
			  <tr>
				<th>Artiklnummer</th>
				<th>Feld</th>
				<th>Alt</th>
				<th>Neu</th>
			  </tr>
			</thead>
			<tbody>
			  <?php foreach ($preview as $p): ?>
				<tr>
				  <td><span class="pv-pill"><?= h((string)$p['sku']) ?></span></td>
				  <td><?= h((string)$p['field']) ?></td>
				  <td><?= h((string)$p['old']) ?></td>
				  <td><?= h((string)$p['new']) ?></td>
				</tr>
			  <?php endforeach; ?>
			</tbody>
		  </table>
		<?php endif; ?>
	  </div>
	</div>

  </div>
</body>
</html>
