<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function pv_norm_code(string $code): string {
  $code = trim($code);
  $code = preg_replace('/\s+/', '', $code);
  return strtoupper((string)$code);
}

function pv_discounts_path(): string {
  return __DIR__ . '/../config/discounts.json';
}

function pv_load_discounts(string $path): array {
  if (!is_file($path)) return [];
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : [];
}

function pv_order_gross(array $order, float $vatRate = 0.19): float {
  if (isset($order['totals']['gross']) && is_numeric($order['totals']['gross'])) {
	return (float)$order['totals']['gross'];
  }
  $sum = 0.0;
  foreach (($order['items'] ?? []) as $it) {
	if (!is_array($it)) continue;
	$qty = (int)($it['qty'] ?? $it['quantity'] ?? 0);

	if (isset($it['price_gross']) && is_numeric($it['price_gross'])) {
	  $sum += max(0, $qty) * (float)$it['price_gross'];
	  continue;
	}
	if (isset($it['price_gross_unit']) && is_numeric($it['price_gross_unit'])) {
	  $sum += max(0, $qty) * (float)$it['price_gross_unit'];
	  continue;
	}
	if (isset($it['price_net_unit']) && is_numeric($it['price_net_unit'])) {
	  $sum += max(0, $qty) * ((float)$it['price_net_unit'] * (1.0 + $vatRate));
	  continue;
	}
  }
  return $sum;
}

/**
 * ✅ Liefert bei Erfolg auch "note" (optional) aus discounts.json
 * discounts.json Eintrag: { code, active, expires, min_gross_eur, type, value, note? }
 */
function pv_validate_discount(string $code, float $gross, array $discounts): array {
  $code = pv_norm_code($code);
  if ($code === '') return ['ok'=>false,'error'=>'Kein Code','note'=>''];

  $today = date('Y-m-d');

  foreach ($discounts as $d) {
	if (!is_array($d)) continue;
	if (pv_norm_code((string)($d['code'] ?? '')) !== $code) continue;

	$note = trim((string)($d['note'] ?? ''));

	if (empty($d['active'])) return ['ok'=>false,'error'=>'Code ist deaktiviert.','note'=>$note];

	$expires = trim((string)($d['expires'] ?? ''));
	if ($expires !== '' && $today > $expires) return ['ok'=>false,'error'=>'Code ist abgelaufen.','note'=>$note];

	$min = (float)($d['min_gross_eur'] ?? 0);
	if ($min > 0 && $gross < $min) return ['ok'=>false,'error'=>'Mindestbestellwert nicht erreicht.','note'=>$note];

	$type  = (string)($d['type'] ?? 'percent');
	$value = (float)($d['value'] ?? 0);
	if ($value <= 0) return ['ok'=>false,'error'=>'Code ist ungültig.','note'=>$note];

	$disc = 0.0;
	if ($type === 'percent') $disc = $gross * ($value / 100.0);
	elseif ($type === 'fixed') $disc = $value;
	else return ['ok'=>false,'error'=>'Code-Typ ungültig.','note'=>$note];

	$disc = min($gross, max(0.0, $disc));

	return [
	  'ok'=>true,
	  'code'=>$code,
	  'discount_gross'=>$disc,
	  'note'=>$note,
	];
  }

  return ['ok'=>false,'error'=>'Code nicht gefunden.','note'=>''];
}

$body = json_decode((string)file_get_contents('php://input'), true);
$orderJson = (string)($body['order_json'] ?? '');
$code      = (string)($body['discount_code'] ?? '');

$order = json_decode($orderJson, true);
if (!is_array($order)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'order_json ungültig','note'=>'']);
  exit;
}

$vatRate = 0.19;
$gross = pv_order_gross($order, $vatRate);

if ($gross <= 0) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Summe ist 0','note'=>'']);
  exit;
}

$discounts = pv_load_discounts(pv_discounts_path());
$val = pv_validate_discount($code, $gross, $discounts);
if (empty($val['ok'])) {
  http_response_code(400);
  echo json_encode($val);
  exit;
}

$disc  = (float)$val['discount_gross'];
$after = max(0.0, $gross - $disc);

echo json_encode([
  'ok' => true,
  'code' => (string)$val['code'],
  'discount_gross' => number_format($disc, 2, '.', ''),
  'gross_after' => number_format($after, 2, '.', ''),
  'gross_before' => number_format($gross, 2, '.', ''),
  'note' => (string)($val['note'] ?? ''),
]);
