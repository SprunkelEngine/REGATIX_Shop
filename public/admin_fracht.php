<?php
declare(strict_types=1);
session_start();

$configPath = __DIR__ . '/../config/config.json';
$shippingJsonPath = __DIR__ . '/../config/shipping_costs.json';

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function load_shipping_costs(string $path): array {
	if (!is_file($path)) return [];
	$raw = file_get_contents($path);
	$arr = json_decode($raw, true);
	return is_array($arr) ? $arr : [];
}
function save_shipping_costs(string $path, array $data): void {
	$dir = dirname($path);
	if (!is_dir($dir)) @mkdir($dir, 0775, true);
	$tmp = $path . '.tmp';
	file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT), LOCK_EX);
	@rename($tmp, $path);
}

$products = [
	'fachbodenregal' => 'Fachbodenregal',
	'schwerlastregal' => 'Schwerlastregal',
	'geschossanlagen' => 'Geschossanlagen',
	'ladenregale' => 'Ladenregale',
	'palettenregale' => 'Palettenregale',
	'weitspannregale' => 'Weitspannregale',
	'sonderposten' => 'Sonderposten',
	// Weitere Produkte …
];
$productKey = $_GET['product'] ?? array_key_first($products);

// Flash
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['shipping_csv'])) {
	$csv = $_FILES['shipping_csv'];
	if ($csv['error'] === UPLOAD_ERR_OK && is_uploaded_file($csv['tmp_name'])) {
		if (($handle = fopen($csv['tmp_name'], 'r')) !== false) {
			$header = fgetcsv($handle, 0, ';');
			$rows = [];
			while (($row = fgetcsv($handle, 0, ';')) !== false) {
				$assoc = [];
				foreach ($header as $i => $col) {
					$val = $row[$i] ?? '';
					// Optionale Typisierung
					if (in_array(strtolower($col), ['netto','brutto'], true)) {
						$val = (float)str_replace(',','.', $val);
					}
					$assoc[trim($col)] = $val;
				}
				$rows[] = $assoc;
			}
			fclose($handle);
			$data = load_shipping_costs($shippingJsonPath);
			$data[$productKey] = $rows;
			save_shipping_costs($shippingJsonPath, $data);
			$msg = 'Frachtkosten erfolgreich importiert ('.count($rows).' Zeilen).';
		} else {
			$msg = 'Fehler beim Lesen der CSV-Datei.';
		}
	} else {
		$msg = 'Bitte eine gültige CSV auswählen!';
	}
}
$shipping = load_shipping_costs($shippingJsonPath);
?>
<!doctype html>
<html lang="de">
<head>
	<meta charset="utf-8">
	<title>Frachtkosten Import</title>
	<style>
		body{font-family:sans-serif;margin:32px;}
		.card{background:#fafbfc;padding:18px 22px;margin-bottom:18px;border-radius:12px;box-shadow:0 4px 24px #0001;}
		.btn{padding:7px 22px;border-radius:8px;border:1px solid #bdbdbd;background:#eaf5dc;}
		table{width:100%;border-collapse:collapse;}
		th,td{border:1px solid #dadada;padding:6px 10px;}
		th{background:#f0f3f8;}
		.small{font-size:13px;color:#777;}
	</style>
</head>
<body>
	<h1>Frachtkosten – Import/Verwaltung</h1>
	<?php if ($msg): ?><div class="card"><?= h($msg) ?></div><?php endif; ?>
	<div class="card">
		<form method="get" style="margin-bottom:18px">
			<label>Produkt wählen:</label>
			<select name="product" onchange="this.form.submit()">
				<?php foreach($products as $k=>$label): ?>
					<option value="<?=h($k)?>" <?=$productKey===$k?'selected':''?>><?=h($label)?></option>
				<?php endforeach; ?>
			</select>
		</form>
		<form method="post" enctype="multipart/form-data">
			<label for="shipping_csv">CSV importieren für <b><?= h($products[$productKey]) ?></b>:</label>
			<input type="file" name="shipping_csv" accept=".csv" required>
			<button class="btn" type="submit">Importieren</button>
			<div class="small" style="margin-top:5px">
				Erwartete Spalten (Header, Reihenfolge egal): <b>Land, PLZ, Ort, Versandzone, netto, brutto</b>
			</div>
		</form>
	</div>
	<div class="card">
		<div><b>Aktuelle Frachtkosten für <?=h($products[$productKey])?>:</b></div>
		<?php if (empty($shipping[$productKey])): ?>
			<div class="small">Noch keine Einträge für dieses Produkt.</div>
		<?php else: ?>
			<div style="overflow-x:auto;">
			<table>
				<thead>
				<tr>
					<?php foreach(array_keys($shipping[$productKey][0]) as $col): ?>
						<th><?= h($col) ?></th>
					<?php endforeach; ?>
				</tr>
				</thead>
				<tbody>
				<?php foreach($shipping[$productKey] as $row): ?>
					<tr>
						<?php foreach($row as $cell): ?>
							<td><?= h((string)$cell) ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div>
			<div class="small" style="margin-top:7px">Datei: <code>config/shipping_costs.json</code></div>
		<?php endif; ?>
	</div>
</body>
</html>
