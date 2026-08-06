<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function normCountry(string $c): string {
  $c = strtoupper(trim($c));
  $c = preg_replace('/[^A-Z]/', '', $c) ?? '';
  return $c;
}

function normZip(string $z): string {
  $z = trim($z);
  $z = preg_replace('/[^0-9A-Za-z\- ]/', '', $z) ?? '';
  return $z;
}

function formatEUR(float $n): string {
  return number_format($n, 2, ',', '.') . ' €';
}

function zipMatches(string $pattern, string $zip): bool {
  $pattern = trim($pattern);
  $zip = trim($zip);

  if ($pattern === '' || $zip === '') return false;

  // Wildcard: "01*" => Prefix-Match
  if (str_contains($pattern, '*')) {
	$prefix = rtrim($pattern, '*');
	return $prefix === '' ? false : str_starts_with($zip, $prefix);
  }

  // exact match
  return strcasecmp($pattern, $zip) === 0;
}

$zip     = normZip((string)($_GET['zip'] ?? ''));
$country = normCountry((string)($_GET['country'] ?? ''));
$category = trim((string)($_GET['category'] ?? '')); // optional

if ($zip === '' || $country === '') {
  out(['ok' => false, 'error' => 'zip/country fehlt'], 400);
}

$jsonPath = __DIR__ . '/../config/shipping_costs.json';
if (!is_file($jsonPath)) {
  out(['ok'=>false, 'error'=>'shipping_costs.json nicht gefunden'], 500);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
  out(['ok'=>false, 'error'=>'shipping_costs.json nicht lesbar'], 500);
}

$root = json_decode($raw, true);
if (!is_array($root)) {
  out(['ok'=>false, 'error'=>'shipping_costs.json ist kein gültiges JSON'], 500);
}

/**
 * Unterstützt 2 Formate:
 * A) Array: [ {..}, {..} ]
 * B) Kategorien-Objekt: { "fachbodenregal": [..], "xyz":[..] }
 */
$lists = [];

// Format A: direktes Array?
$isList = array_keys($root) === range(0, count($root)-1);
if ($isList) {
  $lists['__all__'] = $root;
} else {
  // Format B: Kategorien
  foreach ($root as $k => $v) {
	if (is_array($v)) $lists[(string)$k] = $v;
  }
}

if ($category !== '') {
  if (!isset($lists[$category])) {
	out([
	  'ok'=>true,
	  'found'=>false,
	  'brutto_value'=>0,
	  'netto_value'=>0,
	  'brutto'=>'—',
	  'netto'=>'',
	  'zone'=>'',
	  'category'=>$category,
	  'meta'=>'Kategorie nicht gefunden: ' . $category
	]);
  }
  $searchLists = [$category => $lists[$category]];
} else {
  $searchLists = $lists; // alle Kategorien durchsuchen
}

$best = null;
$bestCat = '';
$bestLen = -1;

// Suche: Land+PLZ (inkl. Wildcards), beste = längstes PLZ-Pattern
foreach ($searchLists as $cat => $data) {
  foreach ($data as $row) {
	if (!is_array($row)) continue;
	$land = normCountry((string)($row['Land'] ?? ''));
	$plz  = normZip((string)($row['PLZ'] ?? ''));
	if ($land === $country && $plz !== '' && zipMatches($plz, $zip)) {
	  $len = strlen($plz);
	  if ($len > $bestLen) {
		$best = $row;
		$bestCat = (string)$cat;
		$bestLen = $len;
	  }
	}
  }
}

if ($best === null) {
  out([
	'ok' => true,
	'found' => false,
	'brutto_value' => 0,
	'netto_value'  => 0,
	'brutto' => '—',
	'netto'  => '',
	'zone'   => '',
	'category' => $category !== '' ? $category : '',
	'meta'   => 'Keine Frachtkosten gefunden für ' . $country . ' ' . $zip
  ]);
}

$net   = (float)($best['netto'] ?? 0);
$gross = (float)($best['brutto'] ?? 0);

$zone = (string)($best['Versandzone'] ?? '');
$ort  = (string)($best['Ort'] ?? '');

$metaParts = [];
if ($bestCat !== '' && $bestCat !== '__all__') $metaParts[] = 'Kategorie: ' . $bestCat;
if ($zone !== '') $metaParts[] = $zone;
if ($ort !== '')  $metaParts[] = $ort;
$metaParts[] = $country . ' ' . $zip;

out([
  'ok' => true,
  'found' => true,

  'brutto_value' => round($gross, 2),
  'netto_value'  => round($net, 2),

  'brutto' => formatEUR($gross),
  'netto'  => $net > 0 ? (formatEUR($net) . ' netto') : '',

  'zone' => $zone,
  'category' => ($bestCat !== '__all__') ? $bestCat : '',
  'meta' => implode(' · ', $metaParts),
]);
