<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * ✅ FIX: Produkt-Keys mit Umlauten erlauben (Unicode)
 * Erlaubt: Buchstaben (Unicode) + Ziffern + _ + -
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
  header('Location: admin_fields.php?token=' . urlencode($token));
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
   ✅ Atomic JSON writer
   =========================== */
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

function pv_sanitize_field_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s);
  if (mb_strlen($s, 'UTF-8') > 80) $s = mb_substr($s, 0, 80, 'UTF-8');
  return $s;
}
function pv_sanitize_field_label(string $s): string {
  $s = trim($s);
  $s = preg_replace('~[\x00-\x1F\x7F]~u', '', $s);
  if (mb_strlen($s, 'UTF-8') > 120) $s = mb_substr($s, 0, 120, 'UTF-8');
  return $s;
}
function pv_norm_field_type(string $t): string {
  $t = strtolower(trim($t));
  return in_array($t, ['text','textarea'], true) ? $t : 'text';
}

function pv_load_fields(string $productKey): array {
  $path = pv_fields_path($productKey);

  if (!is_file($path)) return pv_default_fields_template();

  $raw = json_decode((string)file_get_contents($path), true);
  if (!is_array($raw)) return pv_default_fields_template();

  $raw['overrides'] = (isset($raw['overrides']) && is_array($raw['overrides'])) ? $raw['overrides'] : [];
  $raw['free_text'] = (isset($raw['free_text']) && is_array($raw['free_text'])) ? $raw['free_text'] : [];

  foreach (['overrides','free_text'] as $grp) {
	$norm = [];
	foreach ($raw[$grp] as $f) {
	  if (!is_array($f)) continue;
	  $k = pv_sanitize_field_key((string)($f['key'] ?? ''));
	  if ($k === '') continue;
	  $label = pv_sanitize_field_label((string)($f['label'] ?? $k));
	  $type = pv_norm_field_type((string)($f['type'] ?? 'text'));
	  $norm[] = ['key'=>$k, 'label'=>($label !== '' ? $label : $k), 'type'=>$type];
	}
	$raw[$grp] = $norm;
  }

  return $raw;
}

function pv_save_fields(string $productKey, array $fields): void {
  $path = pv_fields_path($productKey);
  $out = [
	'overrides' => (isset($fields['overrides']) && is_array($fields['overrides'])) ? array_values($fields['overrides']) : [],
	'free_text' => (isset($fields['free_text']) && is_array($fields['free_text'])) ? array_values($fields['free_text']) : [],
  ];
  pv_atomic_write_json($path, $out);
}

/* ===========================
   ✅ Actions (Add/Delete/Reset)
   =========================== */
$msg = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$err = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'add_field_def') {
	  $group = (string)($_POST['fd_group'] ?? 'overrides'); // overrides|free_text
	  if (!in_array($group, ['overrides','free_text'], true)) $group = 'overrides';

	  $k = pv_sanitize_field_key((string)($_POST['fd_key'] ?? ''));
	  $label = pv_sanitize_field_label((string)($_POST['fd_label'] ?? $k));
	  $type = pv_norm_field_type((string)($_POST['fd_type'] ?? 'text'));

	  if ($k === '') throw new RuntimeException('Feld-Key fehlt.');

	  $fields = pv_load_fields($productKey);

	  foreach ($fields[$group] as $f) {
		if (is_array($f) && (string)($f['key'] ?? '') === $k) {
		  throw new RuntimeException('Feld existiert bereits in ' . $group . ': ' . $k);
		}
	  }

	  $fields[$group][] = ['key'=>$k, 'label'=>($label !== '' ? $label : $k), 'type'=>$type];
	  pv_save_fields($productKey, $fields);

	  $_SESSION['pv_flash'] = 'Feld hinzugefügt (' . $group . '): ' . $k;
	  header('Location: admin_fields.php?token=' . urlencode($token) . '#fields');
	  exit;
	}

	if ($action === 'delete_field_def') {
	  $group = (string)($_POST['fd_group'] ?? 'overrides');
	  if (!in_array($group, ['overrides','free_text'], true)) $group = 'overrides';

	  $k = pv_sanitize_field_key((string)($_POST['fd_key'] ?? ''));
	  if ($k === '') throw new RuntimeException('Feld-Key fehlt.');

	  $fields = pv_load_fields($productKey);
	  $fields[$group] = array_values(array_filter($fields[$group], function($f) use ($k){
		return !(is_array($f) && (string)($f['key'] ?? '') === $k);
	  }));

	  pv_save_fields($productKey, $fields);

	  $_SESSION['pv_flash'] = 'Feld gelöscht (' . $group . '): ' . $k;
	  header('Location: admin_fields.php?token=' . urlencode($token) . '#fields');
	  exit;
	}

	if ($action === 'reset_field_defs') {
	  pv_save_fields($productKey, pv_default_fields_template());

	  $_SESSION['pv_flash'] = 'Feld-Definitionen zurückgesetzt (Default).';
	  header('Location: admin_fields.php?token=' . urlencode($token) . '#fields');
	  exit;
	}
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$products = pv_products();
$currentLabel = $products[$productKey] ?? $productKey;
$fieldsDef = pv_load_fields($productKey);

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Produktfelder</title>
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
	select, input[type="text"], textarea{
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
	body.layout-pro textarea{
	  background: rgba(0,0,0,.25) !important;
	  color: var(--text) !important;
	}
	body.layout-pro .card{ background: rgba(255,255,255,.03) !important; }
	body.layout-pro .btn{ background: linear-gradient(135deg, rgba(118,167,255,.18), rgba(0,0,0,.25)) !important; }
	body.layout-pro .btn:hover{ border-color: rgba(118,167,255,.35) !important; }

	.pill-toggle{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  display:inline-flex; align-items:center; gap:8px;
	}
	.pill-toggle:hover{ border-color: rgba(149,191,32,.55); }
	body.layout-pro .pill-toggle{ background: rgba(0,0,0,.18); color: var(--text); }
	body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

	.pill-select{
	  height:38px; padding:0 14px; border-radius:999px;
	  border:1px solid var(--border);
	  background:rgba(0,0,0,.04);
	  color:var(--text);
	  cursor:pointer;
	  min-width:220px;
	}
	.pill-select:hover{ border-color: rgba(149,191,32,.55); }

	.h-sub, .small{ color: var(--muted) !important; }

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }

	.pv-row2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
	@media (max-width: 820px){ .pv-row2{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Produktfelder</div>
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
		<a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Zurück zum Admin</a>
		<a class="btn" href="index.php">Frontend</a>
	  </div>
	</div>

	<?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
	<?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
	</div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

	<div class="card" id="fields" style="border-radius:14px; margin-bottom:12px;">
	  <div class="card-h">
		<div class="card-title">Produktfelder</div>
		<div class="small">Hier legst du die Eingabefelder für dieses Produkt fest (Overrides &amp; Freitext).</div>
	  </div>
	  <div class="card-b">

		<div class="pv-row2">
		  <div>
			<div class="small" style="font-weight:800; margin-bottom:6px;">Overrides</div>
			<?php if (empty($fieldsDef['overrides'])): ?>
			  <div class="small">Keine Override-Felder definiert.</div>
			<?php else: ?>
			  <table class="pv-table">
				<thead><tr><th>Key</th><th>Label</th><th>Typ</th><th>Aktion</th></tr></thead>
				<tbody>
				  <?php foreach ($fieldsDef['overrides'] as $f): if(!is_array($f)) continue; ?>
					<tr>
					  <td><span class="pv-pill"><?= h((string)$f['key']) ?></span></td>
					  <td><?= h((string)($f['label'] ?? $f['key'])) ?></td>
					  <td><?= h((string)($f['type'] ?? 'text')) ?></td>
					  <td style="white-space:nowrap">
						<form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Feld wirklich löschen?');">
						  <input type="hidden" name="token" value="<?= h($token) ?>">
						  <input type="hidden" name="action" value="delete_field_def">
						  <input type="hidden" name="fd_group" value="overrides">
						  <input type="hidden" name="fd_key" value="<?= h((string)$f['key']) ?>">
						  <button class="btn" type="submit" style="height:32px">Löschen</button>
						</form>
					  </td>
					</tr>
				  <?php endforeach; ?>
				</tbody>
			  </table>
			<?php endif; ?>
		  </div>

		  <div>
			<div class="small" style="font-weight:800; margin-bottom:6px;">Freitextfelder</div>
			<?php if (empty($fieldsDef['free_text'])): ?>
			  <div class="small">Keine Freitextfelder definiert.</div>
			<?php else: ?>
			  <table class="pv-table">
				<thead><tr><th>Key</th><th>Label</th><th>Typ</th><th>Aktion</th></tr></thead>
				<tbody>
				  <?php foreach ($fieldsDef['free_text'] as $f): if(!is_array($f)) continue; ?>
					<tr>
					  <td><span class="pv-pill"><?= h((string)$f['key']) ?></span></td>
					  <td><?= h((string)($f['label'] ?? $f['key'])) ?></td>
					  <td><?= h((string)($f['type'] ?? 'textarea')) ?></td>
					  <td style="white-space:nowrap">
						<form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Feld wirklich löschen?');">
						  <input type="hidden" name="token" value="<?= h($token) ?>">
						  <input type="hidden" name="action" value="delete_field_def">
						  <input type="hidden" name="fd_group" value="free_text">
						  <input type="hidden" name="fd_key" value="<?= h((string)$f['key']) ?>">
						  <button class="btn" type="submit" style="height:32px">Löschen</button>
						</form>
					  </td>
					</tr>
				  <?php endforeach; ?>
				</tbody>
			  </table>
			<?php endif; ?>
		  </div>
		</div>

		<div style="height:12px"></div>

		<div class="small" style="font-weight:800; margin-bottom:8px;">Neues Feld hinzufügen</div>
		<form method="post" autocomplete="off" style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="add_field_def">

		  <div style="min-width:220px">
			<label>Gruppe</label>
			<select name="fd_group">
			  <option value="overrides">Overrides</option>
			  <option value="free_text">Freitext</option>
			</select>
		  </div>

		  <div style="min-width:240px">
			<label>Key (eindeutig)</label>
			<input name="fd_key" required placeholder="z.B. Zustand oder maengel">
		  </div>

		  <div style="min-width:260px">
			<label>Label (Anzeige)</label>
			<input name="fd_label" placeholder="z.B. Mängel / Hinweise">
		  </div>

		  <div style="min-width:200px">
			<label>Typ</label>
			<select name="fd_type">
			  <option value="text">Text (1 Zeile)</option>
			  <option value="textarea">Textarea (mehrzeilig)</option>
			</select>
		  </div>

		  <button class="btn" type="submit">Hinzufügen</button>
		</form>

		<div style="height:10px"></div>
		<form method="post" style="margin:0" onsubmit="return confirm('Wirklich auf Default-Felder zurücksetzen?');">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="reset_field_defs">
		  <button class="btn" type="submit" style="height:32px">Auf Default zurücksetzen</button>
		</form>

		<div class="small" style="margin-top:8px">
		  Tipp: Für <strong>Sonderposten</strong> Freitextfelder wie <code>beschreibung_frei</code>, <code>maengel</code>, <code>intern</code> (Typ: <code>textarea</code>) anlegen.
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
