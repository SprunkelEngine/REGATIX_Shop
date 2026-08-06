<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * Produkt-Keys mit Umlauten erlauben (Unicode)
 */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

/**
 * String robust nach UTF-8 bringen
 */
function pv_to_utf8(string $s): string {
  if ($s === '') return '';
  if (mb_check_encoding($s, 'UTF-8')) {
	return $s;
  }

  $enc = mb_detect_encoding($s, ['UTF-8', 'Windows-1252', 'ISO-8859-1', 'ISO-8859-15'], true);
  if ($enc && $enc !== 'UTF-8') {
	$converted = @mb_convert_encoding($s, 'UTF-8', $enc);
	if (is_string($converted) && $converted !== '') {
	  return $converted;
	}
  }

  $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
  if (is_string($converted) && $converted !== '') {
	return $converted;
  }

  $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
  if (is_string($converted) && $converted !== '') {
	return $converted;
  }

  return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
}

/**
 * Rekursiv alle Strings in Arrays nach UTF-8 normalisieren
 */
function pv_normalize_array_utf8($value) {
  if (is_array($value)) {
	$out = [];
	foreach ($value as $k => $v) {
	  $nk = is_string($k) ? pv_to_utf8($k) : $k;
	  $out[$nk] = pv_normalize_array_utf8($v);
	}
	return $out;
  }

  if (is_string($value)) {
	return pv_to_utf8($value);
  }

  return $value;
}

/* ===========================
   Auth Token
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

/* ===========================
   Produktliste (wie in admin.php)
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
   Produktwechsel (Session)
   =========================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'set_product') {
  $_SESSION['pv_product'] = pv_key((string)($_POST['product'] ?? ''));
  header('Location: admin_import.php?token=' . urlencode($token));
  exit;
}

/* ===========================
   Default-Produkt
   =========================== */
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') {
  $_SESSION['pv_product'] = 'fachbodenregal';
}
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

function pv_variants_json_path(string $productKey): string {
  $productKey = pv_key($productKey);
  if ($productKey === '') $productKey = 'fachbodenregal';
  return __DIR__ . '/../data/products/' . $productKey . '/variants.json';
}

function pv_atomic_write_json(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }

  $tmp = $path . '.tmp';

  // alle Strings vorher sicher nach UTF-8 bringen
  $data = pv_normalize_array_utf8($data);

  try {
	$json = json_encode(
	  $data,
	  JSON_UNESCAPED_UNICODE
	  | JSON_UNESCAPED_SLASHES
	  | JSON_PRETTY_PRINT
	  | JSON_INVALID_UTF8_SUBSTITUTE
	  | JSON_THROW_ON_ERROR
	);
  } catch (JsonException $e) {
	throw new RuntimeException('JSON konnte nicht erzeugt werden: ' . $e->getMessage());
  }

  if (file_put_contents($tmp, $json, LOCK_EX) === false) {
	throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);
  }

  if (!@rename($tmp, $path)) {
	if (!@copy($tmp, $path)) {
	  @unlink($tmp);
	  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	}
	@unlink($tmp);
  }
}

/* ===========================
   CSV Import Helper
   =========================== */
function pv_strip_utf8_bom(string $s): string {
  return preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
}

function pv_detect_delimiter(string $line): string {
  $candidates = [
	';'  => substr_count($line, ';'),
	','  => substr_count($line, ','),
	"\t" => substr_count($line, "\t")
  ];
  arsort($candidates);
  $best = array_key_first($candidates);
  return ($best && $candidates[$best] > 0) ? $best : ';';
}

function pv_moneyish_normalize(string $header, string $value): string {
  $header = pv_to_utf8(trim($header));
  $v = pv_to_utf8(trim($value));
  if ($v === '') return '';

  if (strpos($header, '€') !== false && strpos($v, '€') === false) {
	if (preg_match('~^\d+(\.\d{1,2})$~', $v)) {
	  $v = str_replace('.', ',', $v);
	}
	$v .= ' €';
  }

  return $v;
}

function pv_import_csv_to_variants(string $csvTmpPath, float $vatRate): array {
  $raw = (string)file_get_contents($csvTmpPath);
  if ($raw === '') throw new RuntimeException('CSV ist leer.');

  // Encoding robust auf UTF-8 bringen
  $raw = pv_to_utf8($raw);

  // UTF-8 BOM entfernen
  $raw = pv_strip_utf8_bom($raw);

  // Zeilen umbrechen
  $lines = preg_split("/\r\n|\n|\r/", $raw);
  $headerLine = '';
  foreach ($lines as $ln) {
	$ln = pv_to_utf8((string)$ln);
	if (trim($ln) !== '') {
	  $headerLine = $ln;
	  break;
	}
  }

  if ($headerLine === '') throw new RuntimeException('CSV Header fehlt.');

  $delim = pv_detect_delimiter($headerLine);

  $rows = [];
  foreach ($lines as $ln) {
	$ln = pv_to_utf8((string)$ln);
	if (trim($ln) === '') continue;
	$rows[] = str_getcsv($ln, $delim);
  }

  if (count($rows) < 2) throw new RuntimeException('CSV hat keine Datenzeilen.');

  $headers = $rows[0];
  if (!is_array($headers) || empty($headers)) {
	throw new RuntimeException('CSV Header konnte nicht gelesen werden.');
  }

  foreach ($headers as $i => $h) {
	$headers[$i] = pv_to_utf8(trim((string)$h));
  }

  $variants = [];
  for ($r = 1; $r < count($rows); $r++) {
	$cols = $rows[$r];
	if (!is_array($cols)) continue;

	$allEmpty = true;
	foreach ($cols as $c) {
	  if (trim(pv_to_utf8((string)$c)) !== '') {
		$allEmpty = false;
		break;
	  }
	}
	if ($allEmpty) continue;

	$obj = [];
	for ($i = 0; $i < count($headers); $i++) {
	  $key = (string)($headers[$i] ?? '');
	  if ($key === '') continue;

	  $val = pv_to_utf8((string)($cols[$i] ?? ''));
	  $val = trim($val);
	  $val = pv_moneyish_normalize($key, $val);

	  $obj[$key] = ($val === '') ? '' : $val;
	}

	$variants[] = $obj;
  }

  return [
	'generated_at' => date('c'),
	'vat_rate' => $vatRate,
	'variants' => $variants,
  ];
}

/* ===========================
   UI + Actions
   =========================== */
$msg = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$err = '';

$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'import_csv') {
  try {
	if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) {
	  throw new RuntimeException('Keine Datei hochgeladen.');
	}

	$f = $_FILES['csv'];
	if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
	  throw new RuntimeException('Upload-Fehler: ' . (int)($f['error'] ?? -1));
	}

	$tmp = (string)($f['tmp_name'] ?? '');
	if ($tmp === '' || !is_file($tmp)) {
	  throw new RuntimeException('Upload temporär nicht verfügbar.');
	}

	$vatRate = (float)($config['import']['vat_rate'] ?? 0.19);

	$data = pv_import_csv_to_variants($tmp, $vatRate);
	$target = pv_variants_json_path($productKey);

	pv_atomic_write_json($target, $data);

	$_SESSION['pv_flash'] = 'Import erfolgreich: ' . count($data['variants']) . ' Varianten geschrieben → ' . basename($target);
	header('Location: admin.php?token=' . urlencode($token));
	exit;

  } catch (Throwable $e) {
	$err = $e->getMessage();
  }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Import</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
	:root{
	  --bg:#ffffff; --card:#ffffff; --text:#000000; --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14); --accent:#95bf20; --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; }
	select, input[type="file"], input[type="text"]{ background: rgba(0,0,0,.03) !important; color: var(--text) !important; }
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
	body.layout-pro select, body.layout-pro input[type="file"], body.layout-pro input[type="text"]{
	  background: rgba(0,0,0,.25) !important; color: var(--text) !important;
	}
	body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
	body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
	body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }

	.pill-toggle{
	  height:38px; padding:0 14px; border-radius:999px; border:1px solid var(--border);
	  background:rgba(0,0,0,.04); color:var(--text); cursor:pointer; display:inline-flex; align-items:center; gap:8px;
	}
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
	body.layout-pro .pill-toggle{ background: rgba(0,0,0,.18); color: var(--text); }
	body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

	.pill-select{
	  height:38px; padding:0 14px; border-radius:999px; border:1px solid var(--border);
	  background:rgba(0,0,0,.04); color:var(--text); cursor:pointer; min-width:220px;
	}
	.pill-select:hover{ border-color: rgba(149,191,32,.55); }

	.h-sub, .small{ color: var(--muted) !important; }
	code{ background: rgba(0,0,0,.06); padding:2px 6px; border-radius:8px; }
	body.layout-pro code{ background: rgba(255,255,255,.08); }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Import</div>
		<div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong></div>
	  </div>

	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<form method="post" style="margin:0">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="set_product">
		  <select class="pill-select" name="product" onchange="this.form.submit()">
			<?php foreach ($products as $k => $label): ?>
			  <option value="<?= h($k) ?>" <?= ($k === $productKey ? 'selected' : '') ?>><?= h($label) ?></option>
			<?php endforeach; ?>
		  </select>
		</form>

		<button class="pill-toggle" id="pv_layout_toggle" type="button">Layout: Dunkel</button>
		<a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Zurück zum Admin</a>
		<a class="btn" href="index.php">Frontend</a>
	  </div>
	</div>

	<?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
	<?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
	</div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

	<div class="card" style="border-radius:14px;">
	  <div class="card-h">
		<div class="card-title">CSV importieren</div>
		<div class="small">
		  Erwartet Header-Zeile als Keys. Trennzeichen wird automatisch erkannt (z.B. <code>;</code>).
		  Ausgabe: <code><?= h('data/products/' . $productKey . '/variants.json') ?></code>
		</div>
	  </div>
	  <div class="card-b">
		<form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="import_csv">
		  <div style="min-width:320px">
			<label>CSV Datei</label>
			<input type="file" name="csv" accept=".csv,text/csv" required>
		  </div>
		  <button class="btn" type="submit">Import starten</button>
		</form>

		<div class="small" style="margin-top:10px">
		  Hinweis: Werte werden als <strong>Strings</strong> übernommen. Leere Felder werden zu <code>""</code>.
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