<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * ✅ FIX: Produkt-Keys mit Umlauten erlauben (Unicode)
 */
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
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
   ✅ Produktliste
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
	  $list = (is_array($variants) && isset($variants['variants']) && is_array($variants['variants']))
		? $variants['variants']
		: (is_array($variants) ? $variants : []);

	  if (!empty($list[0]) && is_array($list[0])) {
		$v0 = $list[0];
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
  header('Location: admin_patch.php?token=' . urlencode($token));
  exit;
}

/* ===========================
   ✅ Default-Produkt
   =========================== */
if (pv_key((string)($_SESSION['pv_product'] ?? '')) === '') {
  $_SESSION['pv_product'] = 'fachbodenregal';
}
$productKey = pv_key((string)($_SESSION['pv_product'] ?? ''));

/* ===========================
   ✅ JSON IO
   =========================== */
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

function pv_backup_file(string $path): string {
  $dir = dirname($path);
  $base = basename($path);
  $stamp = date('Ymd_His');
  $bak = $dir . '/' . $base . '.bak_' . $stamp;
  if (!@copy($path, $bak)) {
	throw new RuntimeException('Backup fehlgeschlagen: ' . $bak);
  }
  return $bak;
}

/* ===========================
   ✅ CSV Helper
   =========================== */
function pv_strip_utf8_bom(string $s): string {
  return preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
}
function pv_detect_delimiter(string $line): string {
  $candidates = [';' => substr_count($line, ';'), ',' => substr_count($line, ','), "\t" => substr_count($line, "\t")];
  arsort($candidates);
  $best = array_key_first($candidates);
  return ($best && $candidates[$best] > 0) ? $best : ';';
}
function pv_read_csv_table(string $csvTmpPath): array {
  $raw = (string)file_get_contents($csvTmpPath);
  if ($raw === '') throw new RuntimeException('CSV ist leer.');
  $raw = pv_strip_utf8_bom($raw);

  $lines = preg_split("/\r\n|\n|\r/", $raw);
  $headerLine = '';
  foreach ($lines as $ln) { if (trim((string)$ln) !== '') { $headerLine = (string)$ln; break; } }
  if ($headerLine === '') throw new RuntimeException('CSV Header fehlt.');

  $delim = pv_detect_delimiter($headerLine);

  $rows = [];
  foreach ($lines as $ln) {
	if (trim((string)$ln) === '') continue;
	$rows[] = str_getcsv((string)$ln, $delim);
  }
  if (count($rows) < 2) throw new RuntimeException('CSV hat keine Datenzeilen.');

  $headers = $rows[0];
  foreach ($headers as $i => $h) $headers[$i] = trim((string)$h);

  $dataRows = [];
  for ($r=1; $r<count($rows); $r++) {
	$cols = $rows[$r];
	if (!is_array($cols)) continue;

	$allEmpty = true;
	foreach ($cols as $c) { if (trim((string)$c) !== '') { $allEmpty = false; break; } }
	if ($allEmpty) continue;

	$obj = [];
	for ($i=0; $i<count($headers); $i++) {
	  $k = (string)($headers[$i] ?? '');
	  if ($k === '') continue;
	  $obj[$k] = trim((string)($cols[$i] ?? ''));
	}
	$dataRows[] = $obj;
  }

  return [$headers, $dataRows];
}

/* ===========================
   ✅ Primärschlüssel
   =========================== */
function pv_guess_pk_field(array $config): string {
  $pk = (string)($config['variant']['primary_key'] ?? '');
  if ($pk !== '') return $pk;
  return 'Artiklnummer';
}

/* ===========================
   ✅ Patch-Logik + Preview
   =========================== */
function pv_apply_patch_preview(array $variantsJson, array $patchRows, string $pkField, bool $onlyNonEmpty, int $maxChanges = 200): array {
  $list = $variantsJson['variants'] ?? null;
  if (!is_array($list)) throw new RuntimeException('variants.json hat keine "variants"-Liste.');

  $idx = [];
  foreach ($list as $i => $v) {
	if (!is_array($v)) continue;
	$pk = trim((string)($v[$pkField] ?? ''));
	if ($pk === '') continue;
	$idx[$pk] = $i;
  }

  $matched = 0;
  $notFound = 0;
  $changedCells = 0;
  $changedRows = 0;
  $changes = [];

  foreach ($patchRows as $row) {
	if (!is_array($row)) continue;
	$pk = trim((string)($row[$pkField] ?? ''));
	if ($pk === '') continue;

	if (!isset($idx[$pk])) { $notFound++; continue; }
	$matched++;

	$i = $idx[$pk];
	$before = $list[$i];
	if (!is_array($before)) $before = [];

	$rowChanged = false;

	foreach ($row as $col => $val) {
	  $col = (string)$col;
	  if ($col === '' || $col === $pkField) continue;

	  $valStr = (string)$val;
	  if ($onlyNonEmpty && trim($valStr) === '') continue;

	  $old = (string)($before[$col] ?? '');
	  if ($old !== $valStr) {
		$before[$col] = $valStr;
		$changedCells++;
		$rowChanged = true;

		if (count($changes) < $maxChanges) {
		  $changes[] = [
			'pk' => $pk,
			'field' => $col,
			'old' => $old,
			'new' => $valStr,
		  ];
		}
	  }
	}

	if ($rowChanged) {
	  $changedRows++;
	  $list[$i] = $before;
	}
  }

  $variantsJson2 = $variantsJson;
  $variantsJson2['variants'] = $list;
  $variantsJson2['patched_at'] = date('c');

  return [$variantsJson2, [
	'matched' => $matched,
	'not_found' => $notFound,
	'changed_rows' => $changedRows,
	'changed_cells' => $changedCells,
	'changes_sample' => $changes,
	'changes_sample_limit' => $maxChanges,
  ]];
}

/* ===========================
   ✅ UI + Actions
   =========================== */
$msg = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$err = '';
$previewStats = null;
$previewChanges = [];

$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;
$pkField = pv_guess_pk_field($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'patch_csv') {
  try {
	if (!isset($_FILES['csv']) || !is_array($_FILES['csv'])) throw new RuntimeException('Keine Datei hochgeladen.');
	$f = $_FILES['csv'];
	if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Upload-Fehler: ' . (int)($f['error'] ?? -1));
	$tmp = (string)($f['tmp_name'] ?? '');
	if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Upload temporär nicht verfügbar.');

	$onlyNonEmpty = isset($_POST['only_non_empty']) && (string)($_POST['only_non_empty'] ?? '') === '1';
	$dryRun = isset($_POST['dry_run']) && (string)($_POST['dry_run'] ?? '') === '1';

	[$headers, $rows] = pv_read_csv_table($tmp);
	if (!in_array($pkField, $headers, true)) {
	  throw new RuntimeException('CSV muss die PK-Spalte enthalten: "' . $pkField . '".');
	}

	$target = pv_variants_json_path($productKey);
	if (!is_file($target)) throw new RuntimeException('variants.json nicht gefunden: ' . $target);

	$variantsJson = json_decode((string)file_get_contents($target), true);
	if (!is_array($variantsJson)) throw new RuntimeException('variants.json ist kein gültiges JSON.');

	// Preview berechnen (immer)
	[$patched, $stats] = pv_apply_patch_preview($variantsJson, $rows, $pkField, $onlyNonEmpty, 200);

	if ($dryRun) {
	  // nicht schreiben, nur anzeigen
	  $previewStats = $stats;
	  $previewChanges = $stats['changes_sample'] ?? [];
	  $msg = 'Dry-Run: Es wurde NICHT gespeichert. (Siehe Vorschau unten)';
	} else {
	  // Backup + schreiben
	  $bak = pv_backup_file($target);
	  pv_atomic_write_json($target, $patched);

	  $_SESSION['pv_flash'] =
		'Patch gespeichert. Backup: ' . basename($bak) .
		' | Match: ' . $stats['matched'] .
		' | Nicht gefunden: ' . $stats['not_found'] .
		' | Geänderte Zeilen: ' . $stats['changed_rows'] .
		' | Geänderte Zellen: ' . $stats['changed_cells'];

	  header('Location: admin_patch.php?token=' . urlencode($token));
	  exit;
	}

  } catch (Throwable $e) {
	$err = $e->getMessage();
  }
}

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · CSV Patch</title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
	:root{
	  --bg:#ffffff; --card:#ffffff; --text:#000000; --muted:rgba(0,0,0,.65);
	  --border:rgba(0,0,0,.14); --accent:#95bf20; --shadow:0 8px 22px rgba(0,0,0,.08);
	}
	body{ background: var(--bg) !important; }
	select, input[type="file"]{ background: rgba(0,0,0,.03) !important; color: var(--text) !important; }
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
	body.layout-pro select, body.layout-pro input[type="file"]{
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

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:13px; text-align:left; vertical-align:top; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">CSV Patch (Preis-/Feldänderungen)</div>
		<div class="h-sub">Produkt: <strong><?= h($currentLabel) ?></strong> · PK: <code><?= h($pkField) ?></code></div>
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
		<div class="card-title">CSV hochladen und Werte aktualisieren</div>
		<div class="small">
		  CSV muss Spalte <code><?= h($pkField) ?></code> enthalten + die Felder, die überschrieben werden sollen (z.B. <code>ohne MwSt. €</code>, <code>mit MwSt. €</code>, …).
		  Ziel: <code><?= h('data/products/' . $productKey . '/variants.json') ?></code>
		</div>
	  </div>
	  <div class="card-b">
		<form method="post" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="patch_csv">

		  <div style="min-width:320px">
			<label>CSV Datei</label>
			<input type="file" name="csv" accept=".csv,text/csv" required>
		  </div>

		  <label style="display:flex; gap:10px; align-items:center; margin:0">
			<input type="checkbox" name="only_non_empty" value="1" checked style="width:18px; height:18px;">
			<span class="small" style="font-weight:800">Nur nicht-leere CSV-Zellen überschreiben</span>
		  </label>

		  <label style="display:flex; gap:10px; align-items:center; margin:0">
			<input type="checkbox" name="dry_run" value="1" style="width:18px; height:18px;">
			<span class="small" style="font-weight:800">Dry-Run (nur Vorschau, NICHT speichern)</span>
		  </label>

		  <button class="btn" type="submit">Patch ausführen</button>
		</form>

		<div class="small" style="margin-top:10px">
		  Es wird bei jedem echten Patch automatisch ein Backup erzeugt: <code>variants.json.bak_YYYYmmdd_HHMMSS</code>
		</div>
	  </div>
	</div>

	<?php if (is_array($previewStats)): ?>
	  <div style="height:12px"></div>
	  <div class="card" style="border-radius:14px;">
		<div class="card-h">
		  <div class="card-title">Vorschau (Dry-Run)</div>
		  <div class="small">
			Match: <strong><?= h((string)$previewStats['matched']) ?></strong> ·
			Nicht gefunden: <strong><?= h((string)$previewStats['not_found']) ?></strong> ·
			Geänderte Zeilen: <strong><?= h((string)$previewStats['changed_rows']) ?></strong> ·
			Geänderte Zellen: <strong><?= h((string)$previewStats['changed_cells']) ?></strong>
			<?php if (!empty($previewStats['changes_sample_limit'])): ?>
			  · Sample: max <?= h((string)$previewStats['changes_sample_limit']) ?> Änderungen
			<?php endif; ?>
		  </div>
		</div>
		<div class="card-b">
		  <?php if (empty($previewChanges)): ?>
			<div class="small">Keine Änderungen.</div>
		  <?php else: ?>
			<table class="pv-table">
			  <thead>
				<tr>
				  <th><?= h($pkField) ?></th>
				  <th>Feld</th>
				  <th>Alt</th>
				  <th>Neu</th>
				</tr>
			  </thead>
			  <tbody>
				<?php foreach ($previewChanges as $c): ?>
				  <tr>
					<td><span class="pv-pill"><?= h((string)($c['pk'] ?? '')) ?></span></td>
					<td><?= h((string)($c['field'] ?? '')) ?></td>
					<td><?= h((string)($c['old'] ?? '')) ?></td>
					<td><?= h((string)($c['new'] ?? '')) ?></td>
				  </tr>
				<?php endforeach; ?>
			  </tbody>
			</table>
			<div class="small" style="margin-top:10px;">
			  Wenn das passt: Haken bei Dry-Run weg → Patch speichern.
			</div>
		  <?php endif; ?>
		</div>
	  </div>
	<?php endif; ?>

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
