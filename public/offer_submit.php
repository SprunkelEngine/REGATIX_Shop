<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$cfg  = $repo->getConfig();
$shop = (array)($cfg['shop'] ?? []);

// ✅ Empfänger (Betreiber) – bitte passend zu deiner Config setzen
$to = (string)($shop['operator_email'] ?? $shop['email'] ?? 'info@regatix.com');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo "Method not allowed";
  exit;
}

$email        = trim((string)($_POST['email'] ?? ''));
$orderJsonRaw  = (string)($_POST['order_json'] ?? '');
$discountCode  = trim((string)($_POST['discount_code'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  http_response_code(400);
  echo "Bitte eine gültige E-Mail angeben.";
  exit;
}

$order = json_decode($orderJsonRaw, true);
if (!is_array($order)) {
  http_response_code(400);
  echo "Warenkorb-Daten fehlen oder sind ungültig.";
  exit;
}

// --- Inhalt für Mail bauen (robust, ohne Annahmen über exaktes JSON-Schema) ---
$lines = [];
$lines[] = "Angebotsanfrage aus Checkout";
$lines[] = "----------------------------------------";
$lines[] = "Kunden-E-Mail: " . $email;
if ($discountCode !== '') $lines[] = "Rabattcode: " . $discountCode;
$lines[] = "";

$lines[] = "Positionen:";
$lines[] = "----------------------------------------";

// Versuche typische Strukturen zu finden:
$items = [];
if (isset($order['items']) && is_array($order['items'])) $items = $order['items'];
elseif (isset($order['cart']) && is_array($order['cart'])) $items = $order['cart'];

if (!$items) {
  // Fallback: komplette JSON kurz mitgeben (oder weglassen, wenn du das nicht willst)
  $lines[] = "(Positionen nicht eindeutig im JSON gefunden)";
} else {
  foreach ($items as $it) {
	if (!is_array($it)) continue;
	$title = (string)($it['title'] ?? $it['name'] ?? 'Artikel');
	$sku   = (string)($it['sku'] ?? $it['id'] ?? '');
	$qty   = (string)($it['qty'] ?? $it['quantity'] ?? '1');
	$price = (string)($it['price'] ?? $it['gross'] ?? $it['price_gross'] ?? '');

	$row = "- {$title}";
	if ($sku !== '') $row .= " (SKU: {$sku})";
	$row .= " | Menge: {$qty}";
	if ($price !== '') $row .= " | Preis: {$price}";
	$lines[] = $row;
  }
}

$lines[] = "";
$lines[] = "Rohdaten (order_json):";
$lines[] = "----------------------------------------";
$lines[] = $orderJsonRaw;

// --- Mail senden ---
$subject = 'Angebotsanfrage (Checkout)';

$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'From: Regatix Shop <no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'example.com') . '>';
$headers[] = 'Reply-To: ' . $email; // ✅ Antworten gehen an den Kunden
$headersStr = implode("\r\n", $headers);

$ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', implode("\n", $lines), $headersStr);

if (!$ok) {
  http_response_code(500);
  echo "E-Mail konnte nicht versendet werden (mail() fehlgeschlagen).";
  exit;
}

// ✅ Danke-Seite (minimal)
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Angebot angefordert</title>
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <div class="container">
	<div class="card">
	  <div class="card-h"><div class="card-title">Vielen Dank</div></div>
	  <div class="card-b">
		<p>Ihre Angebotsanfrage wurde an den Betreiber gesendet.</p>
		<p><strong>E-Mail:</strong> <?= h($email) ?></p>
		<a class="btn" href="cart.php">Zurück zum Warenkorb</a>
	  </div>
	</div>
  </div>
</body>
</html>
