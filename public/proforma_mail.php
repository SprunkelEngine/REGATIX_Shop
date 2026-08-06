<?php
declare(strict_types=1);

session_start();

// -------------------- Config laden --------------------
$configPath = __DIR__ . '/../config/config.json';
$config = [];
if (is_file($configPath)) {
	$tmp = json_decode((string)file_get_contents($configPath), true);
	if (is_array($tmp)) $config = $tmp;
}

// -------------------- Helpers (aus checkout_submit.php) --------------------
function readable_payment(string $key): string {
	switch ($key) {
		case 'bank_transfer': return 'Vorkasse';
		case 'cash_pickup':   return 'Barzahlung bei Abholung';
		case 'paypal':        return 'PayPal';
		default:              return $key;
	}
}

function pv_norm_code(string $code): string {
	$code = trim($code);
	$code = preg_replace('/\s+/', '', $code);
	return strtoupper((string)$code);
}

function pv_discounts_path(): string { return __DIR__ . '/../config/discounts.json'; }

function pv_load_discounts(string $path): array {
	if (!is_file($path)) return [];
	$raw = (string)file_get_contents($path);
	$arr = json_decode($raw, true);
	return is_array($arr) ? $arr : [];
}

function pv_validate_discount(string $code, float $gross, array $discounts): array {
	$code = pv_norm_code($code);
	if ($code === '') return ['ok'=>false,'discount'=>0.0,'code'=>''];

	$today = date('Y-m-d');
	foreach ($discounts as $d) {
		if (!is_array($d)) continue;
		if (pv_norm_code((string)($d['code'] ?? '')) !== $code) continue;

		if (empty($d['active'])) return ['ok'=>false,'discount'=>0.0,'code'=>$code];

		$expires = trim((string)($d['expires'] ?? ''));
		if ($expires !== '' && $today > $expires) return ['ok'=>false,'discount'=>0.0,'code'=>$code];

		$min = (float)($d['min_gross_eur'] ?? 0);
		if ($min > 0 && $gross < $min) return ['ok'=>false,'discount'=>0.0,'code'=>$code];

		$type  = (string)($d['type'] ?? 'percent');
		$value = (float)($d['value'] ?? 0);
		if ($value <= 0) return ['ok'=>false,'discount'=>0.0,'code'=>$code];

		$disc = 0.0;
		if ($type === 'percent') $disc = $gross * ($value / 100.0);
		elseif ($type === 'fixed') $disc = $value;
		else return ['ok'=>false,'discount'=>0.0,'code'=>$code];

		$disc = min($gross, max(0.0, $disc));
		return ['ok'=>true,'discount'=>$disc,'code'=>$code];
	}
	return ['ok'=>false,'discount'=>0.0,'code'=>$code];
}

// ✅ Money helper: "69,50 € brutto" oder "69,50 €" -> 69.5
function pv_parse_money($v): float {
	$s = trim((string)$v);
	if ($s === '') return 0.0;
	$s = preg_replace('/[^\d,.\-]/', '', $s);
	if ($s === '' || $s === null) return 0.0;
	if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
		$s = str_replace('.', '', $s);
		$s = str_replace(',', '.', $s);
	} else {
		$s = str_replace(',', '.', $s);
	}
	$n = (float)$s;
	return is_finite($n) ? $n : 0.0;
}

// -------------------- Input: kommt aus Checkout via POST --------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo "Method not allowed";
	exit;
}

$order_json = (string)($_POST['order_json'] ?? '');
if ($order_json === '') {
	http_response_code(400);
	echo "order_json fehlt";
	exit;
}

$order = json_decode($order_json, true);
if (!is_array($order) || empty($order['items'])) {
	http_response_code(400);
	echo "order_json ungültig";
	exit;
}

// Kundendaten aus POST (wie checkout_submit)
$kundendaten = $_POST;
$kundennameRaw = trim((string)($kundendaten['first_name'] ?? '') . ' ' . (string)($kundendaten['last_name'] ?? 'Kunde'));
if ($kundennameRaw === '') $kundennameRaw = 'Kunde';

$kundenmail = trim((string)($kundendaten['email'] ?? ''));
$paymentKey = (string)($kundendaten['payment'] ?? '-');
$zahlungsart = readable_payment($paymentKey);

// VAT
$vatRate = (float)($config['import']['vat_rate'] ?? 0.19);

// Waren-Totals (robust aus order)
$netBefore = 0.0;
$grossBefore = 0.0;

if (isset($order['totals']['net']) && is_numeric($order['totals']['net'])) $netBefore = (float)$order['totals']['net'];
if (isset($order['totals']['gross']) && is_numeric($order['totals']['gross'])) $grossBefore = (float)$order['totals']['gross'];

// fallback: aus items
if ($netBefore <= 0 || $grossBefore <= 0) {
	foreach ($order['items'] as $it) {
		$qty = (int)($it['quantity'] ?? $it['qty'] ?? 1);
		if ($qty < 1) $qty = 1;
		$netUnit = (float)($it['price_net_unit'] ?? 0);
		if ($netUnit > 0) $netBefore += $netUnit * $qty;
	}
	if ($grossBefore <= 0 && $netBefore > 0) $grossBefore = $netBefore * (1.0 + $vatRate);
}

// ✅ Fracht aus POST (kommt aus hidden fields)
$freightGross = pv_parse_money($kundendaten['freight_gross'] ?? 0);
$freightNet   = pv_parse_money($kundendaten['freight_net'] ?? 0);
$freightZone  = trim((string)($kundendaten['freight_zone'] ?? ''));

// ✅ Abholung => Fracht 0
if ($paymentKey === 'cash_pickup') {
	$freightGross = 0.0;
	$freightNet   = 0.0;
	$freightZone  = '';
}

// ✅ Rabatt NUR auf Waren (nicht auf Fracht!)
$discountCode = pv_norm_code((string)($_POST['discount_code'] ?? ''));
$discountGross = 0.0;

$grossAfterGoods = $grossBefore;
$netAfterGoods   = $netBefore;

if ($discountCode !== '' && $grossBefore > 0) {
	$discounts = pv_load_discounts(pv_discounts_path());
	$val = pv_validate_discount($discountCode, $grossBefore, $discounts);
	if (!empty($val['ok'])) {
		$discountGross = (float)$val['discount'];
		$grossAfterGoods = max(0.0, $grossBefore - $discountGross);
		$discountNet = $discountGross / (1.0 + $vatRate);
		$netAfterGoods = max(0.0, $netBefore - $discountNet);
	}
}

// ✅ Endsumme = Waren nach Rabatt + Fracht (kein Rabatt auf Fracht)
$grossFinal = $grossAfterGoods + $freightGross;
$netFinal   = $netAfterGoods   + $freightNet;

// Items Tabelle (wie in Mail)
$itemsHtml = '<table class="items">
  <tr>
	<th>Art.-Nr.</th>
	<th>Bezeichnung</th>
	<th>Menge</th>
	<th>Einzelpreis Netto (€)</th>
	<th>Summe Netto (€)</th>
  </tr>';

foreach ($order['items'] as $it) {
	$qty = (int)($it['quantity'] ?? $it['qty'] ?? 1);
	if ($qty < 1) $qty = 1;

	$article = (string)($it['article'] ?? '');
	$title   = (string)($it['title'] ?? '');
	$sel     = (string)($it['selection_text'] ?? '');

	$netUnit = (float)($it['price_net_unit'] ?? $it['price_net'] ?? 0);
	$sumNet  = $netUnit * $qty;

	$itemsHtml .= '<tr>'
	  . '<td>' . htmlspecialchars($article, ENT_QUOTES, 'UTF-8') . '</td>'
	  . '<td>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
	  . ($sel !== '' ? '<br><small>' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') . '</small>' : '')
	  . '</td>'
	  . '<td align="right">' . $qty . '</td>'
	  . '<td align="right">' . number_format($netUnit, 2, ',', '.') . '</td>'
	  . '<td align="right">' . number_format($sumNet, 2, ',', '.') . '</td>'
	  . '</tr>';
}
$itemsHtml .= '</table>';

$fromLogo = 'https://www.regatix.com/media/Logos/rlogo270.png';
$brand = (string)($config['ui']['brand_title'] ?? 'REGATIX');

// ✅ Exakt Mail-Style (leicht ergänzt um Fracht & final)
$html = '
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Proforma-Rechnung (Vorschau)</title>
  <style>
	body { font-family: DejaVu Sans, Arial, sans-serif; padding:16px; }
	.kopf { font-size:1.3em; font-weight:bold; margin-bottom:16px; }
	.box { margin-bottom: 1.3em; }
	.items td, .items th { border: 1px solid #bbb; padding: 6px; }
	.items { border-collapse: collapse; width:100%; margin-bottom:16px;}
	.totals td { padding: 4px; }
	.totals { margin-bottom: 1.4em; }
	.footer { font-size:0.95em; color: #444; margin-top:2em; }
	.logo { margin-bottom: 18px; }
  </style>
</head>
<body>
<div class="logo">
	<img src="' . htmlspecialchars($fromLogo, ENT_QUOTES, 'UTF-8') . '" alt="Regatix" width="180" height="30">
</div>
<div class="kopf">Proforma-Rechnung (Vorschau)</div>

<div class="box">
  <strong>Kunde:</strong><br>
  ' . htmlspecialchars((string)($kundendaten['company'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars($kundennameRaw, ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['street'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['zip'] ?? ''), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($kundendaten['city'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['country'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars($kundenmail, ENT_QUOTES, 'UTF-8') . '
</div>

' . $itemsHtml . '

<table class="totals">
  <tr><td><strong>Netto (Waren):</strong></td><td><strong>' . number_format($netBefore, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Brutto (Waren):</td><td>' . number_format($grossBefore, 2, ',', '.') . ' €</td></tr>';

if ($discountGross > 0) {
  $html .= '
  <tr><td>Rabatt (' . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8') . ') nur Waren:</td><td>- ' . number_format($discountGross, 2, ',', '.') . ' €</td></tr>
  <tr><td><strong>Waren nach Rabatt:</strong></td><td><strong>' . number_format($grossAfterGoods, 2, ',', '.') . ' €</strong></td></tr>';
}

if ($freightGross > 0) {
  $html .= '
  <tr><td>Fracht:</td><td>' . number_format($freightGross, 2, ',', '.') . ' €</td></tr>';
  if ($freightZone !== '') {
	$html .= '<tr><td></td><td><small>' . htmlspecialchars($freightZone, ENT_QUOTES, 'UTF-8') . '</small></td></tr>';
  }
}

$html .= '
  <tr><td><strong>Zu zahlen (gesamt):</strong></td><td><strong>' . number_format($grossFinal, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Zahlungsart:</td><td>' . htmlspecialchars($zahlungsart, ENT_QUOTES, 'UTF-8') . '</td></tr>
</table>

<div class="footer">
  <strong>Hinweis:</strong> Dies ist eine Proforma-Vorschau. Maßgeblich ist die Bestätigung nach Bestellung.<br>
  Freundliche Grüße,<br>
  Ihr REGATIX-Team
</div>
</body>
</html>
';

echo $html;
