<?php
// get_freight.php – für AJAX-Anfrage

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Freight.php';

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$boot = $repo->bootstrap();

$shippingCostsFile = __DIR__ . '/../config/shipping_costs.json';
$shippingData = [];
if (is_file($shippingCostsFile)) {
	$shippingData = json_decode(file_get_contents($shippingCostsFile), true) ?? [];
}

// Deine Warenkorb-Summen:
$warenkorbBrutto = 100.00;
$warenkorbNetto  = 84.03;

// Aus Parameter:
$product = $_GET['product'] ?? 'fachbodenregal';
$country = $_GET['country'] ?? '';
$zip     = $_GET['zip'] ?? '';

function findFreightCost(array $shippingData, string $product, string $land, string $plz): ?array {
	$product = strtolower(trim($product));
	$land    = strtoupper(trim($land));
	$plz     = trim($plz);
	if (!isset($shippingData[$product])) return null;
	foreach ($shippingData[$product] as $row) {
		if ($row['Land'] !== '*' && strtoupper($row['Land']) !== $land) continue;
		if (strpos($row['PLZ'], '-') !== false) {
			list($start, $end) = explode('-', $row['PLZ'], 2);
			if ((int)$plz >= (int)$start && (int)$plz <= (int)$end) return $row;
		} elseif (strpos($row['PLZ'], '*') !== false) {
			$prefix = rtrim($row['PLZ'], '*');
			if (strpos($plz, $prefix) === 0) return $row;
		} else {
			if ($row['PLZ'] === $plz) return $row;
		}
	}
	return null;
}

$freightRow = findFreightCost($shippingData, $product, $country, $zip);

$freightBrutto = $freightRow['brutto'] ?? null;
$freightNetto  = $freightRow['netto']  ?? null;
$freightMeta   = $freightRow
	? "{$freightRow['Land']} {$freightRow['PLZ']} / {$freightRow['Versandzone']}"
	: 'Frachtpreis nicht gefunden';

if ($freightBrutto) {
	$value_html = number_format((float)$freightBrutto, 2, ',', '.') . " €"
		. '<div class="net">(Netto: ' . number_format((float)$freightNetto, 2, ',', '.') . " €)</div>";
} else {
	$value_html = '<span style="color:#e53e3e;font-weight:700;">Auf Anfrage</span>';
}

$sumBrutto = $warenkorbBrutto + ($freightBrutto ?? 0);
$sumNetto  = $warenkorbNetto  + ($freightNetto  ?? 0);

header('Content-Type: application/json');
echo json_encode([
	'meta'      => $freightMeta,
	'value_html'=> $value_html,
	'sum_brutto'=> number_format($sumBrutto, 2, ',', '.'),
	'sum_netto' => number_format($sumNetto, 2, ',', '.')
]);
