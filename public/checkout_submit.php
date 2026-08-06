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

// -------------------- PHPMailer --------------------
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// -------------------- Helpers --------------------
function readable_payment(string $key): string {
    switch ($key) {
        case 'bank_transfer': return 'Vorkasse';
        case 'cash_pickup':   return 'Barzahlung bei Abholung';
        case 'paypal':        return 'PayPal';
        default:              return $key;
    }
}

/* ✅ Rabatt helpers */
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

/**
 * ✅ validiert Code und liefert zusätzlich "note" (optional) aus discounts.json
 * Erwartete Felder in discounts.json pro Eintrag:
 * code, active, expires, min_gross_eur, type (percent|fixed), value, note (optional)
 */
function pv_validate_discount(string $code, float $grossItems, array $discounts): array {
    $code = pv_norm_code($code);
    if ($code === '') return ['ok'=>false,'discount'=>0.0,'code'=>'','note'=>''];

    $today = date('Y-m-d');
    foreach ($discounts as $d) {
        if (!is_array($d)) continue;
        if (pv_norm_code((string)($d['code'] ?? '')) !== $code) continue;

        if (empty($d['active'])) return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>(string)($d['note'] ?? '')];

        $expires = trim((string)($d['expires'] ?? ''));
        if ($expires !== '' && $today > $expires) return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>(string)($d['note'] ?? '')];

        $min = (float)($d['min_gross_eur'] ?? 0);
        if ($min > 0 && $grossItems < $min) return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>(string)($d['note'] ?? '')];

        $type  = (string)($d['type'] ?? 'percent');
        $value = (float)($d['value'] ?? 0);
        if ($value <= 0) return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>(string)($d['note'] ?? '')];

        $disc = 0.0;
        if ($type === 'percent') $disc = $grossItems * ($value / 100.0);
        elseif ($type === 'fixed') $disc = $value;
        else return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>(string)($d['note'] ?? '')];

        // ✅ Rabatt darf NICHT größer als Artikel-Brutto sein
        $disc = min($grossItems, max(0.0, $disc));

        $note = trim((string)($d['note'] ?? ''));
        return ['ok'=>true,'discount'=>$disc,'code'=>$code,'note'=>$note];
    }

    return ['ok'=>false,'discount'=>0.0,'code'=>$code,'note'=>''];
}

/* ✅ Orders JSON: relativer Pfad data/orders.json */
function pv_orders_path(): string {
    return dirname(__DIR__) . '/data/orders.json';
}

function pv_save_order_to_json(array $orderRecord): void {
    $file = pv_orders_path();

    $dir = dirname($file);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            die("Fehler: data/ Ordner konnte nicht angelegt werden: " . htmlspecialchars($dir));
        }
    }

    if (!is_file($file)) {
        if (false === @file_put_contents($file, "[]\n", LOCK_EX)) {
            die("Fehler: orders.json konnte nicht angelegt werden: " . htmlspecialchars($file));
        }
    }

    if (!is_writable($file)) {
        die("Fehler: orders.json ist nicht beschreibbar: " . htmlspecialchars($file));
    }

    $raw = @file_get_contents($file);
    $arr = json_decode($raw ?: '[]', true);
    if (!is_array($arr)) $arr = [];

    $arr[] = $orderRecord;

    $json = json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        die("Fehler: JSON encode failed: " . json_last_error_msg());
    }

    if (false === @file_put_contents($file, $json . "\n", LOCK_EX)) {
        die("Fehler: Schreiben fehlgeschlagen: " . htmlspecialchars($file));
    }
}

/* ------------- Mailversand --------------- */
function sendeMail(
    string $empfaenger,
    string $empfaengerName,
    string $betreff,
    string $bodyHtml,
    ?string $replyTo = null,
    ?string $replyToName = null,
    array $config = []
): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isMail();
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($config['shop']['email'] ?? 'shop@example.com', $config['ui']['brand_title'] ?? 'Shop');
        $mail->addAddress($empfaenger, $empfaengerName);
        if ($replyTo) $mail->addReplyTo($replyTo, $replyToName ?: $empfaengerName);
        $mail->isHTML(true);
        $mail->Subject = $betreff;
        $mail->Body = $bodyHtml;
        $mail->send();
        return true;
    } catch (Exception $e) {
        echo "MAILER ERROR: " . htmlspecialchars($e->getMessage()) . "<br>";
        echo "Mailer-Log: " . htmlspecialchars($mail->ErrorInfo) . "<br>";
        return false;
    }
}

// -------------------- Input prüfen --------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$mode = (string)($_POST['mode'] ?? 'buy'); // offer oder buy

// ======================================================
// MODE: OFFER (nur Mail an Betreiber)
// ======================================================
if ($mode === 'offer') {

    $order_json = (string)($_POST['order_json'] ?? '');
    $emailOffer = trim((string)($_POST['email'] ?? ''));
    $companyOffer = trim((string)($_POST['company'] ?? ''));

    if ($order_json === '' || $emailOffer === '' || !filter_var($emailOffer, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo "Fehler: Angebotsdaten oder gültige E-Mail fehlen.";
        exit;
    }

    $order = json_decode($order_json, true);
    if (!is_array($order) || empty($order['items']) || !is_array($order['items'])) {
        http_response_code(400);
        echo "Fehler: Angebotsanfrage konnte nicht gelesen werden.";
        exit;
    }

    $betreiberMail = $config['shop']['contact_email'] ?? ($config['shop']['email'] ?? 'shop@example.com');
    $brand = (string)($config['ui']['brand_title'] ?? 'Shop');

    // ✅ Rabattcode + Notiz (serverseitig aus discounts.json)
    $discountCode = pv_norm_code((string)($_POST['discount_code'] ?? ''));
    $discountNote = '';

    // Für Offer berechnen wir keine Summen, aber wir können die Notiz trotzdem validieren.
    $discounts = pv_load_discounts(pv_discounts_path());
    if ($discountCode !== '') {
        $val = pv_validate_discount($discountCode, 999999.0, $discounts);
        $discountNote = trim((string)($val['note'] ?? ''));
    }

    $itemsHtml = '<table style="border-collapse:collapse;width:100%;">'
        . '<tr>'
        . '<th style="border:1px solid #bbb;padding:6px;text-align:left;">Art.-Nr.</th>'
        . '<th style="border:1px solid #bbb;padding:6px;text-align:left;">Bezeichnung</th>'
        . '<th style="border:1px solid #bbb;padding:6px;text-align:right;">Menge</th>'
        . '</tr>';

    foreach ($order['items'] as $it) {
        $qty = (int)($it['quantity'] ?? $it['qty'] ?? 1);
        if ($qty < 1) $qty = 1;

        $article = (string)($it['article'] ?? '');
        $title   = (string)($it['title'] ?? '');
        $sel     = (string)($it['selection_text'] ?? '');

        $itemsHtml .= '<tr>'
            . '<td style="border:1px solid #bbb;padding:6px;">' . htmlspecialchars($article, ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="border:1px solid #bbb;padding:6px;">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
                . ($sel !== '' ? '<br><small>' . htmlspecialchars($sel, ENT_QUOTES, 'UTF-8') . '</small>' : '')
            . '</td>'
            . '<td style="border:1px solid #bbb;padding:6px;text-align:right;">' . $qty . '</td>'
            . '</tr>';
    }
    $itemsHtml .= '</table>';

    $offerMailHtml = '
    <!doctype html>
    <html lang="de">
    <head><meta charset="utf-8"><title>Angebotsanfrage</title></head>
    <body style="font-family:Arial,sans-serif;">
      <h2>Angebotsanfrage (Checkout)</h2>
      <p><b>Kunden-E-Mail:</b> ' . htmlspecialchars($emailOffer, ENT_QUOTES, 'UTF-8') . '</p>'
      . ($companyOffer !== '' ? '<p><b>Firma:</b> ' . htmlspecialchars($companyOffer, ENT_QUOTES, 'UTF-8') . '</p>' : '')
      . ($discountCode !== '' ? '<p><b>Aktionscode:</b> ' . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8')
            . ($discountNote !== '' ? ' <br><small><b>Notiz:</b> ' . htmlspecialchars($discountNote, ENT_QUOTES, 'UTF-8') . '</small>' : '')
            . '</p>' : '') . '
      <h3>Positionen</h3>
      ' . $itemsHtml . '
      <hr>
      <p><small>Hinweis: Dies ist eine Angebotsanfrage. Es wurde <b>keine</b> Bestellung gespeichert.</small></p>
    </body>
    </html>';

    $mailAdmin = sendeMail(
        $betreiberMail,
        'Shopbetreiber',
        'Angebotsanfrage (Checkout)',
        $offerMailHtml,
        $emailOffer,
        $emailOffer,
        $config
    );

    if ($mailAdmin) {
        header("Location: danke.php?offer=1");
        exit;
    }

    http_response_code(500);
    echo "Achtung: Angebotsanfrage konnte nicht gesendet werden.";
    exit;
}

// ======================================================
// MODE: BUY
// ======================================================

$order_json = (string)($_POST['order_json'] ?? '');
$kundendaten = $_POST;

if ($order_json === '' || empty($kundendaten['email'])) {
    echo "Fehler: Bestelldaten oder Kundendaten fehlen.";
    exit;
}

$order = json_decode($order_json, true);
if (!is_array($order) || empty($order['items']) || !is_array($order['items'])) {
    echo "Fehler: Bestellung konnte nicht gelesen werden.";
    exit;
}

// -------------------- Kunde / Payment --------------------
$kundennameRaw = trim((string)($kundendaten['first_name'] ?? '') . ' ' . (string)($kundendaten['last_name'] ?? 'Kunde'));
if ($kundennameRaw === '') $kundennameRaw = 'Kunde';

$kundenmail = trim((string)($kundendaten['email'] ?? ''));
$kundenfirma = trim((string)($kundendaten['company'] ?? ''));

$betreiberMail = $config['shop']['contact_email'] ?? ($config['shop']['email'] ?? 'shop@example.com');

$paymentKey = (string)($kundendaten['payment'] ?? '-');
$zahlungsart = readable_payment($paymentKey);

// -------------------- Telefonnummer für Zustellung --------------------
$shipUseAlt = !empty($kundendaten['ship_use_alt']);
$phoneMain = trim((string)($kundendaten['phone'] ?? $kundendaten['ship_phone'] ?? ''));
$phoneAlt  = trim((string)($kundendaten['ship_phone_alt'] ?? ''));

$deliveryPhone = $shipUseAlt
    ? ($phoneAlt !== '' ? $phoneAlt : $phoneMain)
    : $phoneMain;

// -------------------- Alternative Lieferadresse --------------------
$ship = [
    'use_alt'    => $shipUseAlt,
    'company'    => trim((string)($kundendaten['ship_company'] ?? '')),
    'phone'      => $deliveryPhone,
    'first_name' => trim((string)($kundendaten['ship_first_name'] ?? '')),
    'last_name'  => trim((string)($kundendaten['ship_last_name'] ?? '')),
    'street'     => trim((string)($kundendaten['ship_street'] ?? '')),
    'zip'        => trim((string)($kundendaten['ship_zip'] ?? '')),
    'city'       => trim((string)($kundendaten['ship_city'] ?? '')),
    'country'    => trim((string)($kundendaten['ship_country'] ?? 'DE')),
];

if ($shipUseAlt) {
    $need = ['first_name','last_name','street','zip','city','country'];
    foreach ($need as $k) {
        if ($ship[$k] === '') {
            echo "Fehler: Alternative Lieferadresse unvollständig (Feld: " . htmlspecialchars($k) . ")";
            exit;
        }
    }
}

// -------------------- VAT --------------------
$vatRate = (float)($config['import']['vat_rate'] ?? 0.19);

// -------------------- Items normalisieren + Preise/Summen --------------------
$itemsOut = [];
$itemsSumNet = 0.0;
$itemsSumGross = 0.0;

foreach ($order['items'] as $it) {
    $qty = (int)($it['quantity'] ?? $it['qty'] ?? 0);
    if ($qty < 1) $qty = 1;

    $netUnit = (float)($it['price_net_unit'] ?? $it['price_net'] ?? $it['net_unit'] ?? 0);
    $grossUnit = (float)($it['price_gross_unit'] ?? $it['price_gross'] ?? $it['gross_unit'] ?? 0);

    if ($grossUnit <= 0 && $netUnit > 0) $grossUnit = $netUnit * (1.0 + $vatRate);
    if ($netUnit <= 0 && $grossUnit > 0) $netUnit = $grossUnit / (1.0 + $vatRate);

    $sumNet = $netUnit * $qty;
    $sumGross = $grossUnit * $qty;

    $itemsSumNet += $sumNet;
    $itemsSumGross += $sumGross;

    $itemsOut[] = [
        'article' => (string)($it['article'] ?? ''),
        'title' => (string)($it['title'] ?? ''),
        'selection_text' => (string)($it['selection_text'] ?? ''),
        'quantity' => $qty,
        'price_net_unit' => $netUnit,
        'price_gross_unit' => $grossUnit,
        'sum_net' => $sumNet,
        'sum_gross' => $sumGross,
    ];
}

// -------------------- Fracht (aus Hidden Fields) --------------------
// ✅ Abholung => Fracht auf 0 (auch wenn UI was schickt)
$freightGross = 0.0;
$freightNet   = 0.0;
$freightZone  = '';

if ($paymentKey !== 'cash_pickup') {
    $freightGross = (float)($_POST['freight_gross'] ?? 0);
    $freightNet   = (float)($_POST['freight_net'] ?? 0);
    $freightZone  = trim((string)($_POST['freight_zone'] ?? ''));
}

// -------------------- Discount: NUR auf Artikel, NICHT auf Fracht --------------------
$discountCode = pv_norm_code((string)($_POST['discount_code'] ?? ''));
$discountGross = 0.0;
$discountNote  = '';

$grossItemsBefore = $itemsSumGross;
$netItemsBefore   = $itemsSumNet;

$grossItemsAfter = $grossItemsBefore;
$netItemsAfter   = $netItemsBefore;

if ($discountCode !== '' && $grossItemsBefore > 0) {
    $discounts = pv_load_discounts(pv_discounts_path());
    $val = pv_validate_discount($discountCode, $grossItemsBefore, $discounts);
    if (!empty($val['ok'])) {
        $discountGross = (float)$val['discount'];
        $discountNote  = trim((string)($val['note'] ?? ''));

        $grossItemsAfter = max(0.0, $grossItemsBefore - $discountGross);

        $discountNet = $discountGross / (1.0 + $vatRate);
        $netItemsAfter = max(0.0, $netItemsBefore - $discountNet);
    }
}

// Totals inkl. Fracht
$netTotalBefore   = $netItemsBefore + $freightNet;
$grossTotalBefore = $grossItemsBefore + $freightGross;

$netTotalAfter   = $netItemsAfter + $freightNet;
$grossTotalAfter = $grossItemsAfter + $freightGross;

$vatAfter = max(0.0, $grossTotalAfter - $netTotalAfter);

// -------------------- Order Record bauen + speichern --------------------
try {
    $orderId = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
} catch (Throwable $e) {
    $orderId = date('Ymd-His') . '-' . substr(md5((string)microtime(true)), 0, 6);
}

$orderRecord = [
    'id' => $orderId,
    'created_at' => date('c'),
    'created_at_local' => date('d.m.Y H:i'),
    'payment' => [
        'key' => $paymentKey,
        'label' => $zahlungsart,
    ],
    'discount' => [
        'code' => $discountCode,
        'gross' => $discountGross,
        'note' => $discountNote !== '' ? $discountNote : 'Rabatt nur auf Artikel, nicht auf Fracht',
    ],
    'freight' => [
        'gross' => $freightGross,
        'net'   => $freightNet,
        'zone'  => $freightZone,
    ],
    'totals' => [
        'items_net_before' => $netItemsBefore,
        'items_gross_before' => $grossItemsBefore,
        'items_net_after' => $netItemsAfter,
        'items_gross_after' => $grossItemsAfter,

        'net_before' => $netTotalBefore,
        'gross_before' => $grossTotalBefore,
        'net_after' => $netTotalAfter,
        'gross_after' => $grossTotalAfter,

        'vat_rate' => $vatRate,
        'vat_after' => $vatAfter,
    ],
    'customer' => [
        'company' => $kundenfirma,
        'first_name' => trim((string)($kundendaten['first_name'] ?? '')),
        'last_name'  => trim((string)($kundendaten['last_name'] ?? '')),
        'name' => $kundennameRaw,
        'email' => $kundenmail,
        'phone' => $deliveryPhone,
        'street' => trim((string)($kundendaten['street'] ?? '')),
        'zip' => trim((string)($kundendaten['zip'] ?? '')),
        'city' => trim((string)($kundendaten['city'] ?? '')),
        'country' => trim((string)($kundendaten['country'] ?? '')),
        'note' => trim((string)($kundendaten['note'] ?? '')),
    ],
    'shipping' => $ship,
    'items' => $itemsOut,
];

pv_save_order_to_json($orderRecord);

// -------------------- E-Mail HTML bauen --------------------
$brand = (string)($config['ui']['brand_title'] ?? 'Shop');
$fromLogo = 'https://www.regatix.com/media/Logos/rlogo270.png';

$shipHtml = '';
if (!empty($ship['use_alt'])) {
    $shipName = trim($ship['first_name'] . ' ' . $ship['last_name']);
    $shipHtml = '
    <div class="box">
      <strong>Lieferadresse:</strong><br>'
      . ($ship['company'] !== '' ? htmlspecialchars($ship['company'], ENT_QUOTES, 'UTF-8') . '<br>' : '') . '
      ' . htmlspecialchars($shipName, ENT_QUOTES, 'UTF-8') . '<br>
      ' . htmlspecialchars($ship['street'], ENT_QUOTES, 'UTF-8') . '<br>
      ' . htmlspecialchars($ship['zip'], ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($ship['city'], ENT_QUOTES, 'UTF-8') . '<br>
      ' . htmlspecialchars($ship['country'], ENT_QUOTES, 'UTF-8') . '
      ' . ($ship['phone'] !== '' ? '<br><small>Tel: ' . htmlspecialchars($ship['phone'], ENT_QUOTES, 'UTF-8') . '</small>' : '') . '
    </div>';
} else {
    $shipHtml = '
    <div class="box">
      <strong>Lieferadresse:</strong><br>
      Wie Rechnungsadresse<br>'
      . ($deliveryPhone !== '' ? '<small>Tel: ' . htmlspecialchars($deliveryPhone, ENT_QUOTES, 'UTF-8') . '</small>' : '') . '
    </div>';
}

$discountInfoBlock = '';
if ($discountGross > 0 && $discountCode !== '') {
    $discountInfoBlock = '<div class="box"><strong>Aktionscode:</strong> '
        . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8')
        . ($discountNote !== '' ? '<br><small><strong>Notiz:</strong> ' . htmlspecialchars($discountNote, ENT_QUOTES, 'UTF-8') . '</small>' : '')
        . '</div>';
}

$kundenMailHtml = '
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Ihre Bestellung</title>
  <style>
    body { font-family: DejaVu Sans, Arial, sans-serif; }
    .kopf { font-size:1.3em; font-weight:bold; margin-bottom:16px; }
    .box { margin-bottom: 1.2em; }
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

<div class="kopf">Bestellbestätigung (Proforma)</div>

<div class="box">
  <strong>Kunde:</strong><br>
  ' . ($kundenfirma !== '' ? htmlspecialchars($kundenfirma, ENT_QUOTES, 'UTF-8') . '<br>' : '') . '
  ' . htmlspecialchars($kundennameRaw, ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['street'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['zip'] ?? ''), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($kundendaten['city'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars((string)($kundendaten['country'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  ' . htmlspecialchars($kundenmail, ENT_QUOTES, 'UTF-8') . '
  ' . ($deliveryPhone !== '' ? '<br>' . htmlspecialchars($deliveryPhone, ENT_QUOTES, 'UTF-8') : '') . '
</div>

' . $discountInfoBlock . '

' . $shipHtml . '

<table class="items">
  <tr>
    <th>Art.-Nr.</th>
    <th>Bezeichnung</th>
    <th>Menge</th>
    <th>Einzelpreis Netto (€)</th>
    <th>Summe Netto (€)</th>
  </tr>';

foreach ($itemsOut as $item) {
    $kundenMailHtml .= '<tr>';
    $kundenMailHtml .= '<td>' . htmlspecialchars($item['article'], ENT_QUOTES, 'UTF-8') . '</td>';
    $kundenMailHtml .= '<td>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . ($item['selection_text'] !== '' ? '<br><small>' . htmlspecialchars($item['selection_text'], ENT_QUOTES, 'UTF-8') . '</small>' : '') . '</td>';
    $kundenMailHtml .= '<td align="right">' . (int)$item['quantity'] . '</td>';
    $kundenMailHtml .= '<td align="right">' . number_format((float)$item['price_net_unit'], 2, ',', '.') . '</td>';
    $kundenMailHtml .= '<td align="right">' . number_format((float)$item['sum_net'], 2, ',', '.') . '</td>';
    $kundenMailHtml .= '</tr>';
}

$kundenMailHtml .= '</table>';

$kundenMailHtml .= '
<table class="totals">
  <tr><td>Artikel Netto:</td><td><strong>' . number_format($netItemsBefore, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Fracht Netto:</td><td><strong>' . number_format($freightNet, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Netto gesamt:</td><td><strong>' . number_format($netTotalAfter, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>MwSt (' . (int)round($vatRate*100) . '%):</td><td><strong>' . number_format($vatAfter, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td><strong>Brutto gesamt:</strong></td><td><strong>' . number_format($grossTotalAfter, 2, ',', '.') . ' €</strong></td></tr>';

if ($discountGross > 0 && $discountCode !== '') {
    $kundenMailHtml .= '<tr><td>Rabatt (' . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8') . ') nur Artikel:</td><td>- ' . number_format($discountGross, 2, ',', '.') . ' €</td></tr>';
    if ($discountNote !== '') {
        $kundenMailHtml .= '<tr><td colspan="2"><small><strong>Notiz:</strong> ' . htmlspecialchars($discountNote, ENT_QUOTES, 'UTF-8') . '</small></td></tr>';
    }
}

$kundenMailHtml .= '
  <tr><td>Zahlungsart:</td><td>' . htmlspecialchars($zahlungsart, ENT_QUOTES, 'UTF-8') . '</td></tr>
</table>

<div class="footer">
  <strong>Hinweis:</strong> Dies ist eine Proforma-Rechnung. Zahlungsbestätigung erhalten Sie separat.<br>
  Freundliche Grüße,<br>
  Ihr REGATIX-Team
</div>
</body>
</html>';

// -------------------- Admin-Mail HTML --------------------
$shipHtmlAdmin = '';
if (!empty($ship['use_alt'])) {
    $shipName = trim($ship['first_name'] . ' ' . $ship['last_name']);
    $shipHtmlAdmin = '<div class="daten"><b>Lieferadresse:</b> '
        . htmlspecialchars($shipName, ENT_QUOTES, 'UTF-8') . ', '
        . htmlspecialchars($ship['street'], ENT_QUOTES, 'UTF-8') . ', '
        . htmlspecialchars($ship['zip'], ENT_QUOTES, 'UTF-8') . ' '
        . htmlspecialchars($ship['city'], ENT_QUOTES, 'UTF-8') . ', '
        . htmlspecialchars($ship['country'], ENT_QUOTES, 'UTF-8')
        . ($ship['company'] !== '' ? ' (' . htmlspecialchars($ship['company'], ENT_QUOTES, 'UTF-8') . ')' : '')
        . ($ship['phone'] !== '' ? ' <br><b>Telefon Lieferadresse:</b> ' . htmlspecialchars($ship['phone'], ENT_QUOTES, 'UTF-8') : '')
        . '</div>';
} else {
    $shipHtmlAdmin = '<div class="daten"><b>Lieferadresse:</b> Wie Rechnungsadresse'
        . ($deliveryPhone !== '' ? '<br><b>Telefon:</b> ' . htmlspecialchars($deliveryPhone, ENT_QUOTES, 'UTF-8') : '')
        . '</div>';
}

$discountAdminLine = '';
if ($discountGross > 0 && $discountCode !== '') {
    $discountAdminLine = '<b>Aktionscode:</b> ' . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8')
        . ($discountNote !== '' ? ' <br><small><b>Notiz:</b> ' . htmlspecialchars($discountNote, ENT_QUOTES, 'UTF-8') . '</small>' : '')
        . '<br>';
}

$adminMailHtml = '
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <title>Neue Bestellung</title>
  <style>
    body { font-family: Arial, sans-serif; }
    .kopf { font-size:1.2em; font-weight:bold; margin-bottom:12px; }
    .daten { margin-bottom: 12px; }
    .items td, .items th { border: 1px solid #bbb; padding: 4px; font-size: 0.97em;}
    .items { border-collapse: collapse; width:100%; margin-bottom:10px;}
    .totals td { padding: 2px; }
    .footer { font-size:0.95em; color: #444; margin-top:2em; }
  </style>
</head>
<body>
<div class="kopf">Neue Bestellung im Shop</div>
<div class="daten">
  <b>Bestellnummer:</b> ' . htmlspecialchars($orderId, ENT_QUOTES, 'UTF-8') . '<br>
  <b>Bestelldatum:</b> ' . date("d.m.Y H:i") . '<br>
  ' . ($kundenfirma !== '' ? '<b>Firma:</b> ' . htmlspecialchars($kundenfirma, ENT_QUOTES, 'UTF-8') . '<br>' : '') . '
  <b>Kunde:</b> ' . htmlspecialchars($kundennameRaw, ENT_QUOTES, 'UTF-8') . '<br>
  <b>E-Mail:</b> ' . htmlspecialchars($kundenmail, ENT_QUOTES, 'UTF-8') . '<br>
  ' . ($deliveryPhone !== '' ? '<b>Telefon:</b> ' . htmlspecialchars($deliveryPhone, ENT_QUOTES, 'UTF-8') . '<br>' : '') . '
  <b>Adresse:</b> ' . htmlspecialchars((string)($kundendaten['street'] ?? ''), ENT_QUOTES, 'UTF-8') . ', ' . htmlspecialchars((string)($kundendaten['zip'] ?? ''), ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars((string)($kundendaten['city'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  <b>Land:</b> ' . htmlspecialchars((string)($kundendaten['country'] ?? ''), ENT_QUOTES, 'UTF-8') . '<br>
  <b>Zahlung:</b> ' . htmlspecialchars($zahlungsart, ENT_QUOTES, 'UTF-8') . '<br>
  ' . $discountAdminLine . '
</div>
' . $shipHtmlAdmin . '
<table class="items">
  <tr>
    <th>Art.-Nr.</th>
    <th>Bezeichnung</th>
    <th>Menge</th>
    <th>Einzelpreis Netto (€)</th>
    <th>Summe Netto (€)</th>
  </tr>';

foreach ($itemsOut as $item) {
    $adminMailHtml .= '<tr>';
    $adminMailHtml .= '<td>' . htmlspecialchars($item['article'], ENT_QUOTES, 'UTF-8') . '</td>';
    $adminMailHtml .= '<td>' . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . ($item['selection_text'] !== '' ? '<br><small>' . htmlspecialchars($item['selection_text'], ENT_QUOTES, 'UTF-8') . '</small>' : '') . '</td>';
    $adminMailHtml .= '<td align="right">' . (int)$item['quantity'] . '</td>';
    $adminMailHtml .= '<td align="right">' . number_format((float)$item['price_net_unit'], 2, ',', '.') . '</td>';
    $adminMailHtml .= '<td align="right">' . number_format((float)$item['sum_net'], 2, ',', '.') . '</td>';
    $adminMailHtml .= '</tr>';
}

$adminMailHtml .= '</table>
<table class="totals">
  <tr><td>Artikel Netto:</td><td><strong>' . number_format($netItemsBefore, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Fracht Netto:</td><td><strong>' . number_format($freightNet, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>Netto gesamt:</td><td><strong>' . number_format($netTotalAfter, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td>MwSt (' . (int)round($vatRate*100) . '%):</td><td><strong>' . number_format($vatAfter, 2, ',', '.') . ' €</strong></td></tr>
  <tr><td><strong>Brutto gesamt:</strong></td><td><strong>' . number_format($grossTotalAfter, 2, ',', '.') . ' €</strong></td></tr>';

if ($discountGross > 0 && $discountCode !== '') {
    $adminMailHtml .= '<tr><td>Rabatt (' . htmlspecialchars($discountCode, ENT_QUOTES, 'UTF-8') . ') nur Artikel:</td><td>- ' . number_format($discountGross, 2, ',', '.') . ' €</td></tr>';
    if ($discountNote !== '') {
        $adminMailHtml .= '<tr><td colspan="2"><small><strong>Notiz:</strong> ' . htmlspecialchars($discountNote, ENT_QUOTES, 'UTF-8') . '</small></td></tr>';
    }
}

$adminMailHtml .= '<tr><td>Zahlungsart:</td><td>' . htmlspecialchars($zahlungsart, ENT_QUOTES, 'UTF-8') . '</td></tr>
</table>
<div class="footer">
  <b>Kundennotiz:</b> ' . htmlspecialchars((string)($kundendaten['note'] ?? '-'), ENT_QUOTES, 'UTF-8') . '<br>
  <br><small>Automatische Benachrichtigung von REGATIX Shop</small>
</div>
</body>
</html>';

// -------------------- Mailversand --------------------
$mailKunde = sendeMail(
    $kundenmail,
    $kundennameRaw,
    'Ihre Bestellung bei ' . ($config['ui']['brand_title'] ?? 'unserem Shop'),
    $kundenMailHtml,
    $betreiberMail,
    $brand,
    $config
);

$mailAdmin = sendeMail(
    $betreiberMail,
    'Shopbetreiber',
    'Neue Bestellung im Shop',
    $adminMailHtml,
    $kundenmail,
    $kundennameRaw,
    $config
);

if ($mailKunde && $mailAdmin) {
    // ✅ Server-Session-Cart löschen
    unset($_SESSION['cart']);

    // ✅ Browser-Cart löschen (localStorage) über Zwischenseite
    header("Location: clear_cart.php");
    exit;
}

echo "Achtung: Bestätigungsmail konnte nicht gesendet werden.<br>";
if (!$mailKunde) echo "Kundenmail fehlgeschlagen.<br>";
if (!$mailAdmin) echo "Betreibermail fehlgeschlagen.<br>";
echo '<a href="index.php">Zurück zum Shop</a>';