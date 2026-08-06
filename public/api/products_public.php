<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // falls iframe auf anderer Domain liegt (optional)

function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}

function pv_load_json(string $path): array {
  if (!is_file($path)) return [];
  $raw = json_decode((string)file_get_contents($path), true);
  return is_array($raw) ? $raw : [];
}

function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
  return $scheme . '://' . $host;
}

$baseDir = realpath(__DIR__ . '/../../'); // /public/api -> /public -> project root
if (!$baseDir) {
  echo json_encode(['error' => 'BaseDir not found'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$productsDir = $baseDir . '/data/products';
$visibilityPath = $baseDir . '/config/product_visibility.json';
$visibility = pv_load_json($visibilityPath);

// default: wenn Key nicht in visibility steht => sichtbar
function pv_is_visible(string $key, array $visibility): bool {
  if (!array_key_exists($key, $visibility)) return true;
  return (bool)$visibility[$key];
}

$out = [];
if (is_dir($productsDir)) {
  foreach (scandir($productsDir) ?: [] as $d) {
	if ($d === '.' || $d === '..') continue;
	$path = $productsDir . '/' . $d;
	if (!is_dir($path)) continue;

	$key = pv_key($d);
	if ($key === '') continue;

	// Sichtbarkeit
	if (!pv_is_visible($key, $visibility)) continue;

	$variantsPath = $path . '/variants.json';
	if (!is_file($variantsPath)) continue;

	$vj = pv_load_json($variantsPath);
	$variants = [];

	if (isset($vj['variants']) && is_array($vj['variants'])) $variants = $vj['variants'];
	elseif (is_array($vj) && array_is_list($vj)) $variants = $vj;

	// ✅ "aktiv" = variants vorhanden
	if (empty($variants) || !is_array($variants[0] ?? null)) continue;

	// Label aus erster Variante
	$v0 = $variants[0];
	$label = $key;

	$isEckregal = false;
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
	if ($isEckregal) continue;

	$out[] = [
	  'key' => $key,
	  'label' => $label,
	  'url' => pv_base_url() . '/public/index.php?product=' . rawurlencode($key),
	];
  }
}

// Sort by label
usort($out, fn($a,$b) => strnatcasecmp((string)$a['label'], (string)$b['label']));

echo json_encode([
  'generated_at' => date('c'),
  'count' => count($out),
  'products' => $out,
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
