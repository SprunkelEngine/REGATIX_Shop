<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function read_json_input(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function load_discounts(string $path): array {
  if (!is_file($path)) return [];
  $raw = file_get_contents($path);
  $arr = json_decode((string)$raw, true);
  return is_array($arr) ? $arr : [];
}

function save_discounts(string $path, array $data): void {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function norm_code(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\s+/', '', $code);
  return strtoupper((string)$code);
}

function parse_date(?string $ymd): ?int {
  $ymd = trim((string)$ymd);
  if ($ymd === '') return null;
  $ts = strtotime($ymd . ' 23:59:59');
  return $ts ?: null;
}

function get_total_gross_cents(array $order): ?int {
  // 1) totals.gross_cents
  if (isset($order['totals']['gross_cents']) && is_numeric($order['totals']['gross_cents'])) {
	return (int)$order['totals']['gross_cents'];
  }
  // 2) sum_gross_cents
  if (isset($order['sum_gross_cents']) && is_numeric($order['sum_gross_cents'])) {
	return (int)$order['sum_gross_cents'];
  }
  // 3) totals.gross (float)
  if (isset($order['totals']['gross']) && is_numeric($order['totals']['gross'])) {
	return (int)round(((float)$order['totals']['gross']) * 100);
  }
  // 4) sum_gross (float)
  if (isset($order['sum_gross']) && is_numeric($order['sum_gross'])) {
	return (int)round(((float)$order['sum_gross']) * 100);
  }
  // 5) items
  if (isset($order['items']) && is_array($order['items'])) {
	$sum = 0;
	foreach ($order['items'] as $it) {
	  if (!is_array($it)) continue;
	  $qty = (int)($it['qty'] ?? $it['quantity'] ?? 1);
	  if ($qty < 1) $qty = 1;

	  // bevorzugt cents
	  if (isset($it['gross_cents']) && is_numeric($it['gross_cents'])) {
		$sum += ((int)$it['gross_cents']) * $qty;
		continue;
	  }
	  // oder price_gross / gross
	  $p = null;
	  if (isset($it['price_gross']) && is_numeric($it['price_gross'])) $p = (float)$it['price_gross'];
	  if (isset($it['gross']) && is_numeric($it['gross'])) $p = (float)$it['gross'];
	  if ($p !== null) $sum += (int)round($p * 100) * $qty;
	}
	return $sum > 0 ? $sum : null;
  }
  return null;
}

function set_totals_from_gross(array &$order, int $gross_cents): void {
  if (!isset($order['totals']) || !is_array($order['totals'])) $order['totals'] = [];
  $order['totals']['gross_cents'] = $gross_cents;

  // wenn net_cents existiert, lassen wir es (oder rechnen optional 19% raus – lieber NICHT raten)
  if (!isset($order['totals']['net_cents'])) {
	// optional: wenn es irgendwo net gab, versuchen wir es
	if (isset($order['totals']['net']) && is_numeric($order['totals']['net'])) {
	  $order['totals']['net_cents'] = (int)round(((float)$order['totals']['net']) * 100);
	}
  }
}

function format_eur_from_cents(int $cents): string {
  $v = number_format($cents / 100, 2, ',', '.');
  return $v . ' €';
}

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$cfg  = $repo->getConfig();

$discountsPath = __DIR__ . '/../config/discounts.json';
$discounts = load_discounts($discountsPath);

$in = read_json_input();
$action = (string)($in['action'] ?? 'apply');
$order  = (array)($in['order'] ?? []);

if ($action === 'clear') {
  // Rabatt rausnehmen (Totals zurücksetzen, wenn original gespeichert)
  if (isset($order['discount']['original_gross_cents']) && is_numeric($order['discount']['original_gross_cents'])) {
	$orig = (int)$order['discount']['original_gross_cents'];
	unset($order['discount']);
	set_totals_from_gross($order, $orig);
  } else {
	unset($order['discount']);
  }

  json_out(['ok' => true, 'order' => $order, 'message' => 'Rabatt entfernt.']);
}

$code = norm_code((string)($in['code'] ?? ''));
if ($code === '') json_out(['ok' => false, 'error' => 'Kein Rabattcode angegeben.'], 400);

$totalGross = get_total_gross_cents($order);
if ($totalGross === null || $totalGross <= 0) {
  json_out(['ok' => false, 'error' => 'Warenkorb-Summe konnte nicht ermittelt werden.'], 400);
}

// falls schon Rabatt drauf ist: erst zurück auf original
if (isset($order['discount']['original_gross_cents']) && is_numeric($order['discount']['original_gross_cents'])) {
  $totalGross = (int)$order['discount']['original_gross_cents'];
}

$found = null;
foreach ($discounts as $d) {
  if (!is_array($d)) continue;
  if (norm_code((string)($d['code'] ?? '')) === $code) { $found = $d; break; }
}
if (!$found) json_out(['ok' => false, 'error' => 'Rabattcode ist ungültig.'], 400);

if (!empty($found['active']) === false) {
  json_out(['ok' => false, 'error' => 'Rabattcode ist deaktiviert.'], 400);
}

// Ablaufdatum prüfen
$expTs = parse_date((string)($found['expires'] ?? ''));
if ($expTs !== null && time() > $expTs) {
  json_out(['ok' => false, 'error' => 'Rabattcode ist abgelaufen.'], 400);
}

// Mindestbestellwert (gross_cents)
$minGross = 0;
if (isset($found['min_gross_eur']) && is_numeric($found['min_gross_eur'])) {
  $minGross = (int)round(((float)$found['min_gross_eur']) * 100);
} elseif (isset($found['min_gross_cents']) && is_numeric($found['min_gross_cents'])) {
  $minGross = (int)$found['min_gross_cents'];
}
if ($minGross > 0 && $totalGross < $minGross) {
  json_out(['ok' => false, 'error' => 'Mindestbestellwert für diesen Code: ' . format_eur_from_cents($minGross)], 400);
}

// Rabatt berechnen
$type  = strtolower((string)($found['type'] ?? 'percent'));
$value = $found['value'] ?? 0;

$discountCents = 0;

if ($type === 'percent') {
  $pct = (float)$value;
  if ($pct <= 0) json_out(['ok' => false, 'error' => 'Rabattcode ist fehlerhaft konfiguriert.'], 400);
  $discountCents = (int)round($totalGross * ($pct / 100));
} elseif ($type === 'fixed') {
  // value als EUR
  $eur = (float)$value;
  if ($eur <= 0) json_out(['ok' => false, 'error' => 'Rabattcode ist fehlerhaft konfiguriert.'], 400);
  $discountCents = (int)round($eur * 100);
} else {
  json_out(['ok' => false, 'error' => 'Unbekannter Rabatt-Typ.'], 400);
}

if ($discountCents <= 0) json_out(['ok' => false, 'error' => 'Rabatt ergibt 0 €.'], 400);
if ($discountCents > $totalGross) $discountCents = $totalGross;

$newGross = $totalGross - $discountCents;

// Order patchen
$order['discount'] = [
  'code' => $code,
  'type' => $type,
  'value' => $value,
  'amount_cents' => $discountCents,
  'amount_display' => '-' . format_eur_from_cents($discountCents),
  'original_gross_cents' => $totalGross
];

set_totals_from_gross($order, $newGross);

// Hinweistext
$human = ($type === 'percent')
  ? ((float)$value . '%')
  : (format_eur_from_cents((int)round(((float)$value)*100)));

json_out([
  'ok' => true,
  'order' => $order,
  'message' => 'Rabattcode ' . $code . ' angewendet (' . $human . ').'
]);
