<?php
// ... evtl. weitere Includes ...
function pv_products(): array {
	$out = [];
	$base = __DIR__ . '/data/products';
	$statusFile = __DIR__ . '/data/product_groups_status.json';
	$status = [];
	if (file_exists($statusFile)) {
		$status = json_decode(file_get_contents($statusFile), true) ?: [];
	}
	if (!is_dir($base)) return $out;

	foreach (scandir($base) ?: [] as $d) {
		if ($d === '.' || $d === '..') continue;
		$path = $base . '/' . $d;
		if (!is_dir($path)) continue;
		// Check Status
		if (isset($status[$d]) && !$status[$d]) continue; // deaktiviert? dann überspringen
		// Optional: Label hübsch machen
		$labels = [
			'fachbodenregal' => 'Fachbodenregal',
			'eckregal' => 'Eckregal',
			// ... ergänzen ...
		];
		$out[$d] = $labels[$d] ?? ucfirst($d);
	}
	return $out;
}

// Produkte laden
$products = pv_products();
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Produktauswahl</title>
  <!-- Dein CSS ... -->
</head>
<body>
	<h1>Wählen Sie eine Produktgruppe</h1>
	<form>
		<select name="product">
			<?php foreach ($products as $key => $label): ?>
				<option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
			<?php endforeach; ?>
		</select>
	</form>
	<!-- Weitere Seite ... -->
</body>
</html>
