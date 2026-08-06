<?php
declare(strict_types=1);

session_start();

$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)file_get_contents($configPath), true) ?: [];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ===========================
   ✅ Auth Token (wie admin.php)
   =========================== */
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token). Setze security.admin_token in config/config.json.";
  exit;
}

// Flash Message (PRG)
$flash = (string)($_SESSION['pv_flash'] ?? '');
unset($_SESSION['pv_flash']);
$msg = $flash !== '' ? $flash : '';
$err = '';

/* ===========================
   ✅ Hilfsfunktionen
   =========================== */
function pv_discounts_path(): string { return __DIR__ . '/../config/discounts.json'; }
function pv_products_dir(): string { return __DIR__ . '/../data/products'; }

function pv_norm_code(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\s+/', '', $code);
  return strtoupper((string)$code);
}

function pv_parse_excluded_products_from_array(array $values): array {
  $out = [];
  foreach ($values as $v) {
	$v = trim((string)$v);
	if ($v === '') continue;
	$out[] = $v;
  }
  $out = array_values(array_unique($out));
  sort($out, SORT_NATURAL | SORT_FLAG_CASE);
  return $out;
}

/* ===========================
   ✅ Produkte aus data/products/ Ordnern laden
   =========================== */
function pv_load_products_from_folders(string $dir): array {
  if (!is_dir($dir)) return [];

  $items = [];
  $list = @scandir($dir);
  if (!is_array($list)) return [];

  foreach ($list as $entry) {
	if ($entry === '.' || $entry === '..') continue;

	$fullPath = $dir . '/' . $entry;
	if (!is_dir($fullPath)) continue;

	$items[] = [
	  'key' => $entry,
	  'id' => $entry,
	  'sku' => '',
	  'name' => $entry,
	  'path' => $fullPath,
	];
  }

  usort($items, function(array $a, array $b): int {
	return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });

  return $items;
}

/* ===========================
   ✅ Rabattcodes
   =========================== */
function pv_load_discounts(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  if (!is_array($arr)) return [];

  foreach ($arr as $i => $d) {
	if (!is_array($d)) continue;
	if (!isset($d['excluded_products']) || !is_array($d['excluded_products'])) {
	  $arr[$i]['excluded_products'] = [];
	} else {
	  $arr[$i]['excluded_products'] = pv_parse_excluded_products_from_array($d['excluded_products']);
	}
  }

  return $arr;
}

function pv_save_discounts(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
	throw new RuntimeException('Konnte Ordner nicht anlegen: ' . $dir);
  }

  $tmp = $path . '.tmp';
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  if ($json === false) throw new RuntimeException('discounts.json konnte nicht erzeugt werden.');
  if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Konnte nicht schreiben: ' . $tmp);

  if (!@rename($tmp, $path)) {
	if (!@copy($tmp, $path)) {
	  @unlink($tmp);
	  throw new RuntimeException('Konnte Datei nicht ersetzen: ' . $path);
	}
	@unlink($tmp);
  }
}

$discountsPath = pv_discounts_path();
$productsDir = pv_products_dir();

$discounts = pv_load_discounts($discountsPath);
$products = pv_load_products_from_folders($productsDir);

// Edit Discount (Form prefill)
$editDiscount = null;
if (isset($_GET['edit_discount'])) {
  $c = pv_norm_code((string)$_GET['edit_discount']);
  foreach ($discounts as $d) {
	if (is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $c) {
	  $editDiscount = $d;
	  break;
	}
  }
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = (string)($_POST['action'] ?? '');

	if ($action === 'save_discount') {
	  $code = pv_norm_code((string)($_POST['d_code'] ?? ''));
	  $type = strtolower(trim((string)($_POST['d_type'] ?? 'percent')));
	  $valueRaw = trim((string)($_POST['d_value'] ?? '0'));
	  $active = isset($_POST['d_active']) && (string)($_POST['d_active'] ?? '') === '1';
	  $expires = trim((string)($_POST['d_expires'] ?? ''));
	  $minGrossRaw = trim((string)($_POST['d_min_gross_eur'] ?? '0'));
	  $note = trim((string)($_POST['d_note'] ?? ''));
	  $excludedProducts = pv_parse_excluded_products_from_array((array)($_POST['d_excluded_products'] ?? []));

	  if ($code === '') throw new RuntimeException('Rabattcode fehlt.');
	  if (!in_array($type, ['percent','fixed'], true)) throw new RuntimeException('Rabatt-Typ ist ungültig.');
	  if ($valueRaw === '' || !is_numeric($valueRaw) || (float)$valueRaw <= 0) throw new RuntimeException('Rabatt-Wert ist ungültig.');
	  if ($minGrossRaw !== '' && (!is_numeric($minGrossRaw) || (float)$minGrossRaw < 0)) throw new RuntimeException('Mindestbestellwert ist ungültig.');
	  if ($expires !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~', $expires)) throw new RuntimeException('Expires muss YYYY-MM-DD sein (oder leer).');

	  $newEntry = [
		'code' => $code,
		'type' => $type,
		'value' => (float)$valueRaw,
		'active' => $active,
		'expires' => $expires,
		'min_gross_eur' => (float)$minGrossRaw,
		'note' => $note,
		'excluded_products' => $excludedProducts,
	  ];

	  $found = false;
	  foreach ($discounts as $i => $d) {
		if (is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $code) {
		  $discounts[$i] = $newEntry;
		  $found = true;
		  break;
		}
	  }
	  if (!$found) $discounts[] = $newEntry;

	  pv_save_discounts($discountsPath, array_values($discounts));

	  $_SESSION['pv_flash'] = 'Rabattcode gespeichert: ' . $code;
	  header('Location: admin_discounts.php?token=' . urlencode($token));
	  exit;
	}

	if ($action === 'delete_discount') {
	  $code = pv_norm_code((string)($_POST['d_code'] ?? ''));
	  if ($code === '') throw new RuntimeException('Code fehlt.');

	  $discounts = array_values(array_filter($discounts, function($d) use ($code){
		return !(is_array($d) && pv_norm_code((string)($d['code'] ?? '')) === $code);
	  }));

	  pv_save_discounts($discountsPath, $discounts);

	  $_SESSION['pv_flash'] = 'Rabattcode gelöscht: ' . $code;
	  header('Location: admin_discounts.php?token=' . urlencode($token));
	  exit;
	}
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$discounts = pv_load_discounts($discountsPath);
$products = pv_load_products_from_folders($productsDir);

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Rabattcodes</title>
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
	select, input[type="text"], input[type="number"], textarea, input:not([type="checkbox"]){
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
	body.layout-pro input[type="number"],
	body.layout-pro input:not([type="checkbox"]),
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
	body.layout-pro .pill-toggle:hover{ border-color: rgba(118,167,255,.35); }

	.h-sub, .small{ color: var(--muted) !important; }

	.pv-table{ width:100%; border-collapse:collapse; }
	.pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
	.pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
	.pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
	.pv-row2{ display:grid; grid-template-columns: 1fr 1fr; gap:10px; }
	.pv-list{ margin:0; padding-left:18px; }
	.pv-list li{ margin:0 0 4px 0; }

	.pv-product-picker{
	  border:1px solid var(--border);
	  border-radius:12px;
	  padding:12px;
	  max-height:340px;
	  overflow:auto;
	  background: rgba(0,0,0,.02);
	}
	body.layout-pro .pv-product-picker{
	  background: rgba(0,0,0,.18);
	}
	.pv-product-item{
	  display:flex;
	  align-items:flex-start;
	  gap:10px;
	  padding:8px 4px;
	  border-bottom:1px solid var(--border);
	}
	.pv-product-item:last-child{
	  border-bottom:0;
	}
	.pv-product-meta{
	  display:flex;
	  flex-direction:column;
	  gap:2px;
	}
	.pv-product-name{
	  font-weight:700;
	  line-height:1.25;
	}
	.pv-product-sub{
	  font-size:12px;
	  color:var(--muted);
	  line-height:1.3;
	}

	@media (max-width: 820px){ .pv-row2{ grid-template-columns:1fr; } }
  </style>
</head>
<body>
  <div class="container">
	<div class="header">
	  <div>
		<div class="h-title">Rabattcodes</div>
		<div class="h-sub">Speichert in <code>config/discounts.json</code> (für Checkout-Rabatte).</div>
	  </div>

	  <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center">
		<button class="pill-toggle" id="pv_layout_toggle" type="button" title="Layout umschalten">Layout: Dunkel</button>
		<a class="btn" href="admin.php?token=<?= urlencode($token) ?>">Zurück zum Admin</a>
	  </div>
	</div>

	<?php if ($msg): ?><div class="card" style="border-radius:14px"><div class="card-b"><?= h($msg) ?></div></div><div style="height:10px"></div><?php endif; ?>
	<?php if ($err): ?><div class="warn"><div class="warn-top"><div class="tri" aria-hidden="true">
	  <svg viewBox="0 0 24 24" width="22" height="22" fill="none"><path d="M12 3 1.8 20.3c-.4.7.1 1.7.9 1.7h18.6c.8 0 1.3-1 .9-1.7L12 3Z" stroke="rgba(255,77,77,.95)" stroke-width="1.6"/></svg>
	</div><div><h4>Fehler</h4><div class="small"><?= h($err) ?></div></div></div></div><div style="height:10px"></div><?php endif; ?>

	<div class="card" style="border-radius:14px; margin-bottom:12px;">
	  <div class="card-h">
		<div class="card-title">Übersicht</div>
		<div class="small">Bestehende Codes + Bearbeiten/Löschen.</div>
	  </div>
	  <div class="card-b">

		<?php if (empty($discounts)): ?>
		  <div class="small">Noch keine Rabattcodes vorhanden.</div>
		<?php else: ?>
		  <table class="pv-table">
			<thead>
			  <tr>
				<th>Code</th>
				<th>Typ</th>
				<th>Wert</th>
				<th>Aktiv</th>
				<th>Expires</th>
				<th>Min. Brutto</th>
				<th>Ausgeschlossene Produkte</th>
				<th>Aktionen</th>
			  </tr>
			</thead>
			<tbody>
			  <?php foreach ($discounts as $d): if (!is_array($d)) continue; ?>
				<?php $excluded = is_array($d['excluded_products'] ?? null) ? $d['excluded_products'] : []; ?>
				<tr>
				  <td><span class="pv-pill"><?= h((string)($d['code'] ?? '')) ?></span></td>
				  <td><?= h((string)($d['type'] ?? '')) ?></td>
				  <td><?= h((string)($d['value'] ?? '')) ?></td>
				  <td><?= !empty($d['active']) ? 'Ja' : 'Nein' ?></td>
				  <td><?= h((string)($d['expires'] ?? '')) ?></td>
				  <td><?= h((string)($d['min_gross_eur'] ?? 0)) ?> €</td>
				  <td>
					<?php if (!empty($excluded)): ?>
					  <ul class="pv-list">
						<?php foreach ($excluded as $item): ?>
						  <li><code><?= h((string)$item) ?></code></li>
						<?php endforeach; ?>
					  </ul>
					<?php else: ?>
					  <span class="small">Keine</span>
					<?php endif; ?>
				  </td>
				  <td style="white-space:nowrap">
					<a class="btn" style="display:inline-block; height:32px; line-height:30px"
					   href="admin_discounts.php?token=<?= urlencode($token) ?>&edit_discount=<?= urlencode((string)($d['code'] ?? '')) ?>">Bearbeiten</a>

					<form method="post" style="display:inline-block; margin:0" onsubmit="return confirm('Rabattcode wirklich löschen?')">
					  <input type="hidden" name="token" value="<?= h($token) ?>">
					  <input type="hidden" name="action" value="delete_discount">
					  <input type="hidden" name="d_code" value="<?= h((string)($d['code'] ?? '')) ?>">
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

	<?php
	  $ed = $editDiscount ?: [];
	  $edCode = (string)($ed['code'] ?? '');
	  $edType = (string)($ed['type'] ?? 'percent');
	  $edValue = (string)($ed['value'] ?? '10');
	  $edActive = !empty($ed['active']);
	  $edExpires = (string)($ed['expires'] ?? '');
	  $edMinGross = (string)($ed['min_gross_eur'] ?? '0');
	  $edNote = (string)($ed['note'] ?? '');
	  $edExcludedProducts = pv_parse_excluded_products_from_array((array)($ed['excluded_products'] ?? []));
	?>

	<div class="card" style="border-radius:14px;">
	  <div class="card-h">
		<div class="card-title"><?= $edCode !== '' ? ('Code bearbeiten: ' . h($edCode)) : 'Neuen Rabattcode anlegen' ?></div>
		<div class="small">Code, Typ, Wert, Mindestbestellwert, Ablaufdatum, Produktausschlüsse und Notiz.</div>
	  </div>
	  <div class="card-b">
		<form method="post" autocomplete="off">
		  <input type="hidden" name="token" value="<?= h($token) ?>">
		  <input type="hidden" name="action" value="save_discount">

		  <div class="pv-row2">
			<div>
			  <label>Code</label>
			  <input name="d_code" required placeholder="z.B. WELCOME10" value="<?= h($edCode) ?>">
			</div>
			<div>
			  <label>Typ</label>
			  <select name="d_type">
				<option value="percent" <?= $edType==='percent'?'selected':'' ?>>Prozent</option>
				<option value="fixed" <?= $edType==='fixed'?'selected':'' ?>>Fixbetrag (EUR)</option>
			  </select>
			</div>
		  </div>

		  <div class="pv-row2" style="margin-top:10px">
			<div>
			  <label>Wert</label>
			  <input name="d_value" required value="<?= h($edValue) ?>" placeholder="10 oder 5.00">
			  <div class="small" style="margin-top:6px">Bei <strong>Fixbetrag</strong> ist der Wert in EUR (z.B. 5.00).</div>
			</div>
			<div>
			  <label>Min. Brutto (EUR, optional)</label>
			  <input name="d_min_gross_eur" value="<?= h($edMinGross) ?>" placeholder="0">
			</div>
		  </div>

		  <div class="pv-row2" style="margin-top:10px">
			<div>
			  <label>Expires (optional, YYYY-MM-DD)</label>
			  <input name="d_expires" value="<?= h($edExpires) ?>" placeholder="2026-12-31">
			</div>
			<div style="display:flex; align-items:flex-end">
			  <label style="display:flex; gap:10px; align-items:center; margin:0">
				<input type="checkbox" name="d_active" value="1" <?= $edActive || $edCode==='' ? 'checked' : '' ?> style="width:18px; height:18px;">
				<span class="small" style="font-weight:800">Aktiv</span>
			  </label>
			</div>
		  </div>

		  <div style="margin-top:12px">
			<label>Produkte vom Rabatt ausschließen</label>

			<?php if (empty($products)): ?>
			  <div class="small" style="margin-top:6px">
				Keine Produkt-Ordner in <code>data/products/</code> gefunden.
			  </div>
			<?php else: ?>
			  <div class="pv-product-picker" style="margin-top:8px">
				<?php foreach ($products as $p): ?>
				  <?php
					$key = (string)($p['key'] ?? '');
					$checked = in_array($key, $edExcludedProducts, true);
				  ?>
				  <label class="pv-product-item">
					<input
					  type="checkbox"
					  name="d_excluded_products[]"
					  value="<?= h($key) ?>"
					  <?= $checked ? 'checked' : '' ?>
					  style="width:18px; height:18px; margin-top:2px;"
					>
					<span class="pv-product-meta">
					  <span class="pv-product-name"><?= h((string)($p['name'] ?? $key)) ?></span>
					  <span class="pv-product-sub">
						Ordner: <code><?= h($key) ?></code>
					  </span>
					</span>
				  </label>
				<?php endforeach; ?>
			  </div>
			  <div class="small" style="margin-top:6px">Angehakte Produkte sind für diesen Rabattcode ausgeschlossen.</div>
			<?php endif; ?>
		  </div>

		  <div style="margin-top:10px">
			<label>Notiz (optional)</label>
			<textarea name="d_note" style="width:100%; min-height:110px; padding:12px; border-radius:12px; border:1px solid var(--border);"><?= h($edNote) ?></textarea>
		  </div>

		  <div style="height:10px"></div>
		  <button class="btn" type="submit">Rabattcode speichern</button>
		  <a class="btn" href="admin_discounts.php?token=<?= urlencode($token) ?>" style="margin-left:8px">Neu anlegen</a>
		</form>
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