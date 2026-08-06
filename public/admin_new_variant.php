<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../src/Repository.php';

use PV\Repository;

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * ✅ Produkt-Keys mit Umlauten erlauben (Unicode)
 */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

/**
 * ✅ Feld-Keys normalisieren
 * (wir lassen Unicode zu, entfernen aber Problemzeichen)
 */
function pv_field_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  // erlaubt Unicode, Zahlen, _ - und Leerzeichen (falls ihr Keys mit Space habt)
  $s = preg_replace('~[^\p{L}\p{N}_\-\s]~u', '', $s);
  $s = trim($s);
  return $s ?: '';
}

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
   ✅ Produktwechsel (Session)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: admin_new_variant.php?token=' . urlencode($token));
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

$boot = $repo->bootstrap();
$variants = $boot['variants'] ?? [];

$pkField = (string)($cfg['variant']['primary_key'] ?? 'Artikelnummer');
if ($pkField === '') $pkField = 'Artikelnummer';

$msg = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$err = '';

/* ===========================
   ✅ fields.json pro Produkt
   =========================== */
function pv_fields_file(string $productKey): string {
  return __DIR__ . '/../data/products/' . $productKey . '/fields.json';
}

/**
 * Load selected fields from fields.json (format: {"fields":[{"key":"..","label":".."}]})
 */
function pv_load_selected_fields(string $productKey): array {
  $path = pv_fields_file($productKey);
  if (!is_file($path)) return [];
  $j = json_decode((string)file_get_contents($path), true);
  if (!is_array($j) || !isset($j['fields']) || !is_array($j['fields'])) return [];

  $out = [];
  foreach ($j['fields'] as $f) {
	if (!is_array($f)) continue;
	$k = pv_field_key((string)($f['key'] ?? ''));
	if ($k === '') continue;
	$label = (string)($f['label'] ?? $k);
	$out[$k] = $label;
  }
  return $out;
}

/**
 * Available fields (Kandidaten) aus:
 * - config variant.dimensions (+ label)
 * - price_net / price_gross
 * - plus MUSS: Produktart + PK
 * - plus: bereits gespeicherte user-defined fields aus fields.json (damit sie nicht verschwinden)
 */
function pv_available_fields(array $cfg, string $pkField, array $selectedFromFile): array {
  $out = [];

  // MUSS
  $out['Produktart'] = 'Produktart';
  $out[$pkField] = $pkField;

  // Config dims
  foreach (($cfg['variant']['dimensions'] ?? []) as $d) {
	if (!is_array($d) || !isset($d['key'])) continue;
	$k = pv_field_key((string)$d['key']);
	if ($k === '') continue;
	$label = (string)($d['label'] ?? $k);
	$out[$k] = $label;
  }

  // Prices
  $priceNet  = (string)($cfg['variant']['price_net'] ?? '');
  $priceGross= (string)($cfg['variant']['price_gross'] ?? '');
  if ($priceNet !== '')  $out[pv_field_key($priceNet)]   = $priceNet;
  if ($priceGross !== '') $out[pv_field_key($priceGross)] = $priceGross;

  // User-defined aus fields.json behalten
  foreach ($selectedFromFile as $k => $label) {
	if (!isset($out[$k])) $out[$k] = $label;
  }

  // Ordnung: MUSS zuerst, dann alphabetisch
  $must = [
	'Produktart' => $out['Produktart'],
	$pkField => $out[$pkField],
  ];
  unset($out['Produktart'], $out[$pkField]);
  asort($out, SORT_NATURAL | SORT_FLAG_CASE);

  return $must + $out;
}

/* ===========================
   ✅ Felder speichern (Checkbox UI + neue Felder)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_fields') {
  try {
	if ($productKey === '') throw new RuntimeException('Kein Produkt gewählt.');

	$selectedFromFile = pv_load_selected_fields($productKey);
	$available = pv_available_fields($cfg, $pkField, $selectedFromFile);

	$selected = (array)($_POST['fields_selected'] ?? []);
	$labelsIn = (array)($_POST['fields_label'] ?? []);

	// ✅ Neue Felder (Key + Label)
	$newKeys = (array)($_POST['new_field_key'] ?? []);
	$newLabels = (array)($_POST['new_field_label'] ?? []);

	for ($i=0; $i<count($newKeys); $i++) {
	  $nk = pv_field_key((string)($newKeys[$i] ?? ''));
	  $nl = trim((string)($newLabels[$i] ?? ''));
	  if ($nk === '') continue;
	  if ($nl === '') $nl = $nk;

	  // ins available rein, damit es gespeichert werden kann
	  if (!isset($available[$nk])) $available[$nk] = $nl;

	  // auto-aktivieren
	  if (!in_array($nk, $selected, true)) $selected[] = $nk;

	  // Label setzen
	  $labelsIn[$nk] = $nl;
	}

	// MUSS-Felder erzwingen
	$must = ['Produktart', $pkField];
	foreach ($must as $m) {
	  if (!in_array($m, $selected, true)) $selected[] = $m;
	}

	// fields.json bauen – Reihenfolge: MUSS zuerst, dann rest alphabetisch nach label
	$fields = [];

	// MUSS zuerst
	foreach (['Produktart', $pkField] as $m) {
	  $label = isset($labelsIn[$m]) ? trim((string)$labelsIn[$m]) : '';
	  if ($label === '') $label = (string)($available[$m] ?? $m);
	  $fields[] = ['key' => $m, 'label' => $label];
	}

	// Rest sortieren
	$rest = [];
	foreach ($available as $k => $defaultLabel) {
	  if ($k === 'Produktart' || $k === $pkField) continue;
	  if (!in_array($k, $selected, true)) continue;
	  $label = isset($labelsIn[$k]) ? trim((string)$labelsIn[$k]) : '';
	  if ($label === '') $label = (string)$defaultLabel;
	  $rest[$k] = $label;
	}
	asort($rest, SORT_NATURAL | SORT_FLAG_CASE);
	foreach ($rest as $k => $label) {
	  $fields[] = ['key' => $k, 'label' => $label];
	}

	$store = ['fields' => $fields];

	$path = pv_fields_file($productKey);
	$dir = dirname($path);
	if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	  throw new RuntimeException('Konnte Zielordner nicht anlegen: ' . $dir);
	}

	$tmp = $path . '.tmp';
	$json = json_encode($store, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
	if ($json === false) throw new RuntimeException('Konnte fields.json nicht erzeugen.');
	if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
	if (!@rename($tmp, $path)) {
	  if (!@copy($tmp, $path)) {
		@unlink($tmp);
		throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	  }
	  @unlink($tmp);
	}

	$_SESSION['pv_flash'] = 'Felder gespeichert.';
	header('Location: admin_new_variant.php?token=' . urlencode($token));
	exit;

  } catch (Throwable $e) {
	$err = $e->getMessage();
  }
}

/* ===========================
   ✅ Load fields for create form
   =========================== */
$selectedFromFile = pv_load_selected_fields($productKey);
$availableFields = pv_available_fields($cfg, $pkField, $selectedFromFile);

// Wenn noch nichts gespeichert: Default = alles außer PK, aber Produktart MUSS
if (empty($selectedFromFile)) {
  $selectedFromFile = [];
  foreach ($availableFields as $k => $label) {
	if ($k === $pkField) continue;
	$selectedFromFile[$k] = $label;
  }
  $selectedFromFile['Produktart'] = $selectedFromFile['Produktart'] ?? 'Produktart';
}

// Für Create-Form: PK nicht als normales Feld rendern
$fieldsForCreate = $selectedFromFile;
unset($fieldsForCreate[$pkField]);

/* ===========================
   ✅ datalist Vorschläge aus vorhandenen Varianten
   (ABER: Produktart bekommt ausdrücklich KEINE Vorschläge)
   =========================== */
$optionsByKey = [];
foreach ($variants as $vv) {
  if (!is_array($vv)) continue;
  foreach ($fieldsForCreate as $k => $_label) {
	if ($k === 'Produktart') continue; // ✅ keine Vorschläge
	if (!isset($vv[$k])) continue;
	$val = trim((string)$vv[$k]);
	if ($val === '') continue;
	$optionsByKey[$k][$val] = true;
  }
}

/* ===========================
   ✅ Create Variant
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'create_variant') {
  try {
	$newPk = trim((string)($_POST['new_pk'] ?? ''));
	if ($newPk === '') throw new RuntimeException('Bitte eine ' . $pkField . ' angeben.');

	$jsonPath = __DIR__ . '/../data/products/' . $productKey . '/variants.json';

	$store = [];
	if (is_file($jsonPath)) {
	  $store = json_decode((string)file_get_contents($jsonPath), true);
	}
	if (!is_array($store)) $store = [];
	if (!isset($store['variants']) || !is_array($store['variants'])) $store['variants'] = [];
	if (!isset($store['vat_rate'])) $store['vat_rate'] = (float)($cfg['import']['vat_rate'] ?? 0.19);

	foreach ($store['variants'] as $v) {
	  if (is_array($v) && isset($v[$pkField]) && (string)$v[$pkField] === $newPk) {
		throw new RuntimeException('Variante existiert bereits: ' . $newPk);
	  }
	}

	$variant = [$pkField => $newPk];

	$keys = (array)($_POST['k'] ?? []);
	$vals = (array)($_POST['v'] ?? []);
	foreach ($vals as $safe => $valueRaw) {
	  $realKey = isset($keys[$safe]) ? (string)$keys[$safe] : '';
	  $realKey = pv_field_key($realKey);
	  if ($realKey === '' || $realKey === $pkField) continue;
	  $value = trim((string)$valueRaw);
	  if ($value === '') continue;
	  $variant[$realKey] = $value;
	}

	// ✅ MUSS: Produktart
	if (!isset($variant['Produktart']) || trim((string)$variant['Produktart']) === '') {
	  throw new RuntimeException('Bitte eine Produktart angeben.');
	}

	$store['variants'][] = $variant;

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

	$_SESSION['pv_flash'] = 'Variante "' . $newPk . '" angelegt.';
	header('Location: admin.php?token=' . urlencode($token) . '&article=' . urlencode($newPk));
	exit;

  } catch (Throwable $e) {
	$err = $e->getMessage();
  }
}

// Produktliste für Umschalter
$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;

// UI-Defaults für „Felder verwalten“
$currentSelectedKeys = array_keys(pv_load_selected_fields($productKey));
if (empty($currentSelectedKeys)) {
  $currentSelectedKeys = array_keys($availableFields);
  $currentSelectedKeys = array_values(array_filter($currentSelectedKeys, fn($k) => $k !== $pkField));
  if (!in_array('Produktart', $currentSelectedKeys, true)) $currentSelectedKeys[] = 'Produktart';
}
if (!in_array('Produktart', $currentSelectedKeys, true)) $currentSelectedKeys[] = 'Produktart';
if (!in_array($pkField, $currentSelectedKeys, true)) $currentSelectedKeys[] = $pkField;

// Labels aus fields.json falls vorhanden
$labelsMap = pv_load_selected_fields($productKey);

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Neue Variante</title>
  <link rel="stylesheet" href="assets/styles.css">

  <style>
	:root{
	  --bg:#ffffff;
	  --card:#ffffff;
	  --text:#0b0e14;
	  --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14);
	  --accent:#95bf20;
	  --shadow:0 10px 28px rgba(0,0,0,.08);
	  --input:rgba(0,0,0,.03);
	}
	body{ background: var(--bg) !important; color: var(--text); }
	.card{ background: var(--card); box-shadow: var(--shadow); border-radius: 16px; border:1px solid var(--border); }
	.muted{ color: var(--muted); }
	select, input[type="text"], input[type="number"], textarea{
	  background: var(--input) !important;
	  color: var(--text) !important;
	  border:1px solid var(--border) !important;
	  border-radius: 12px;
	  padding: 10px 12px;
	}
	textarea{ min-height: 110px; }

	body.layout-pro{
	  --bg:#0b0e14;
	  --card:rgba(255,255,255,.04);
	  --text:#e8f0ff;
	  --muted:#9ab0c7;
	  --border:rgba(255,255,255,.10);
	  --accent:#76a7ff;
	  --shadow:0 14px 40px rgba(0,0,0,.40);
	  --input:rgba(0,0,0,.30);
	  background:
		radial-gradient(1200px 600px at 30% 0%, rgba(118,167,255,.18), transparent 55%),
		radial-gradient(1200px 600px at 70% 0%, rgba(118,255,214,.08), transparent 55%),
		var(--bg) !important;
	}

	.pill-toggle{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  display:inline-flex; align-items:center; gap:8px;
	}
	body.layout-pro .pill-toggle{ background: rgba(0,0,0,.18); }
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
	body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

	.pill-select{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  min-width:220px;
	}

	.grid-2{
	  display:grid;
	  grid-template-columns: 1fr 1fr;
	  gap:16px;
	  align-items:start;
	}
	@media (max-width: 980px){ .grid-2{ grid-template-columns: 1fr; } }

	.fields-grid{
	  display:grid;
	  grid-template-columns: 1fr 1fr;
	  gap:10px 12px;
	}
	@media (max-width: 720px){ .fields-grid{ grid-template-columns: 1fr; } }

	.field-row{
	  display:flex;
	  gap:10px;
	  align-items:center;
	  padding:10px 12px;
	  border:1px solid var(--border);
	  border-radius:14px;
	  background: rgba(255,255,255,.02);
	}
	.field-row label{ margin:0; font-weight:600; }
	.field-row .grow{ flex:1; }
	.field-row input[type="text"]{ width:100%; }

	.form-grid{
	  display:grid;
	  grid-template-columns: 1fr 1fr;
	  gap:12px 14px;
	}
	.form-grid .full{ grid-column: 1 / -1; }
	@media (max-width: 980px){
	  .form-grid{ grid-template-columns: 1fr; }
	  .form-grid .full{ grid-column: auto; }
	}

	.hint{ font-size: 13px; color: var(--muted); margin-top: 6px; }

	.add-row{
	  display:grid;
	  grid-template-columns: 1fr 1fr auto;
	  gap:10px;
	  align-items:end;
	  margin-top: 12px;
	  padding-top: 12px;
	  border-top: 1px solid var(--border);
	}
	@media (max-width: 720px){
	  .add-row{ grid-template-columns: 1fr; }
	}
  </style>
</head>
<body>
  <div class="container">

	<div class="header">
	  <div>
		<div class="h-title">Neue Variante anlegen</div>
		<div class="h-sub muted">Produkt: <strong><?= h($currentLabel) ?></strong></div>
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

		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Zurück zum Admin</a>
		<a class="btn" href="index.php">Frontend</a>
	  </div>
	</div>

	<?php if ($msg): ?>
	  <div class="card"><div class="card-b"><?= h($msg) ?></div></div>
	  <div style="height:12px"></div>
	<?php endif; ?>

	<?php if ($err): ?>
	  <div class="warn"><div class="warn-top">
		<div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div>
	  </div></div>
	  <div style="height:12px"></div>
	<?php endif; ?>

	<div class="grid-2">

	  <!-- ✅ Felder verwalten -->
	  <div class="card">
		<div class="card-h"><div class="card-title">Felder verwalten</div></div>
		<div class="card-b">
		  <div class="hint">
			Wähle, welche Felder beim Anlegen angezeigt werden. <strong><?= h($pkField) ?></strong> und <strong>Produktart</strong> sind Pflicht.
		  </div>

		  <div style="height:12px"></div>

		  <form method="post" id="fieldsForm">
			<input type="hidden" name="token" value="<?= h($token) ?>">
			<input type="hidden" name="action" value="save_fields">

			<div class="fields-grid">
			  <?php foreach ($availableFields as $k => $defaultLabel):
				$isMust = ($k === 'Produktart' || $k === $pkField);
				$checked = in_array($k, $currentSelectedKeys, true) || $isMust;
				$labelVal = isset($labelsMap[$k]) ? $labelsMap[$k] : (string)$defaultLabel;
			  ?>
				<div class="field-row">
				  <input
					type="checkbox"
					name="fields_selected[]"
					value="<?= h($k) ?>"
					<?= $checked ? 'checked' : '' ?>
					<?= $isMust ? 'disabled' : '' ?>
				  >
				  <?php if ($isMust): ?>
					<input type="hidden" name="fields_selected[]" value="<?= h($k) ?>">
				  <?php endif; ?>

				  <div class="grow">
					<label><?= h($k) ?><?= $isMust ? ' (Pflicht)' : '' ?></label>
					<input
					  type="text"
					  name="fields_label[<?= h($k) ?>]"
					  value="<?= h($labelVal) ?>"
					  placeholder="Label"
					>
				  </div>
				</div>
			  <?php endforeach; ?>
			</div>

			<!-- ✅ Neues Feld hinzufügen -->
			<div class="add-row" id="addFields">
			  <div>
				<label>Neues Feld (Key)</label>
				<input type="text" id="new_field_key" placeholder="z.B. Farbe">
			  </div>
			  <div>
				<label>Neues Feld (Label)</label>
				<input type="text" id="new_field_label" placeholder="z.B. Farbe">
			  </div>
			  <div>
				<button class="btn" type="button" id="btn_add_field">+ Hinzufügen</button>
			  </div>
			  <div class="hint full" style="grid-column:1/-1; margin-top:-4px;">
				Tipp: Key ist der technische Schlüssel in der Variante (wird in <code>variants.json</code> gespeichert).
			  </div>
			</div>

			<!-- Hidden inputs, werden per JS befüllt -->
			<div id="new_fields_container"></div>

			<div style="height:14px"></div>
			<button class="btn" type="submit">Felder speichern</button>
			<div class="hint">Speichert intern nach <code>data/products/&lt;produkt&gt;/fields.json</code>.</div>
		  </form>
		</div>
	  </div>

	  <!-- ✅ Variante anlegen -->
	  <div class="card">
		<div class="card-h"><div class="card-title">Variante anlegen</div></div>
		<div class="card-b">
		  <form method="post" autocomplete="off">
			<input type="hidden" name="token" value="<?= h($token) ?>">
			<input type="hidden" name="action" value="create_variant">

			<div class="form-grid">
			  <div class="full">
				<label><?= h($pkField) ?> <span class="hint">(Pflicht)</span></label>
				<input name="new_pk" required placeholder="<?= h($pkField) ?>">
			  </div>

			  <?php foreach ($fieldsForCreate as $realKey => $label):
				$safe = 'f_' . substr(md5($realKey), 0, 10);
			  ?>
				<input type="hidden" name="k[<?= h($safe) ?>]" value="<?= h($realKey) ?>">

				<div>
				  <label><?= h($label) ?><?= ($realKey === 'Produktart' ? ' (Pflicht)' : '') ?></label>

				  <?php if ($realKey === 'Produktart'): ?>
					<!-- ✅ Produktart ohne Vorschläge -->
					<input
					  name="v[<?= h($safe) ?>]"
					  placeholder="<?= h($realKey) ?>"
					  required
					  autocomplete="off"
					>
				  <?php else: ?>
					<input
					  name="v[<?= h($safe) ?>]"
					  list="dl_<?= h($safe) ?>"
					  placeholder="<?= h($realKey) ?>"
					>
					<datalist id="dl_<?= h($safe) ?>">
					  <?php if (isset($optionsByKey[$realKey])): ?>
						<?php foreach (array_keys($optionsByKey[$realKey]) as $opt): ?>
						  <option value="<?= h($opt) ?>"></option>
						<?php endforeach; ?>
					  <?php endif; ?>
					</datalist>
				  <?php endif; ?>

				</div>

			  <?php endforeach; ?>

			  <div class="full" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:6px;">
				<button class="btn" type="submit">Variante anlegen</button>
				<div class="hint">
				  Hinweis: Speichert in <code>variants.json</code> und springt danach in den Admin zur Detailpflege.
				</div>
			  </div>
			</div>

		  </form>
		</div>
	  </div>

	</div>

  </div>

  <script>
	// Darkmode Toggle wie Admin
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

	// ✅ Neues Feld hinzufügen: erzeugt hidden inputs new_field_key[] / new_field_label[]
	(function(){
	  const btn = document.getElementById('btn_add_field');
	  const kIn = document.getElementById('new_field_key');
	  const lIn = document.getElementById('new_field_label');
	  const host = document.getElementById('new_fields_container');

	  function addHiddenPair(key, label){
		const k = (key || '').trim();
		if(!k) return;

		const l = (label || '').trim() || k;

		const i1 = document.createElement('input');
		i1.type = 'hidden';
		i1.name = 'new_field_key[]';
		i1.value = k;

		const i2 = document.createElement('input');
		i2.type = 'hidden';
		i2.name = 'new_field_label[]';
		i2.value = l;

		host.appendChild(i1);
		host.appendChild(i2);
	  }

	  if(btn){
		btn.addEventListener('click', function(){
		  addHiddenPair(kIn.value, lIn.value);
		  kIn.value = '';
		  lIn.value = '';
		  kIn.focus();
		});
	  }

	  // Enter in den Inputs soll auch hinzufügen
	  [kIn, lIn].forEach(el => {
		if(!el) return;
		el.addEventListener('keydown', function(e){
		  if(e.key === 'Enter'){
			e.preventDefault();
			addHiddenPair(kIn.value, lIn.value);
			kIn.value = '';
			lIn.value = '';
			kIn.focus();
		  }
		});
	  });
	})();
  </script>
</body>
</html>
