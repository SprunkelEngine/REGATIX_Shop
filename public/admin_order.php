<?php
declare(strict_types=1);

session_start();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function pv_key(string $s): string {
  $s = trim($s);
  if ($s === '') return '';
  $s = preg_replace('~[^\p{L}\p{N}_\-]~u', '', $s);
  return $s ?: '';
}
function pv_money(float $v): string {
  return number_format($v, 2, ',', '.') . ' €';
}
function pv_base_url(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/* ===========================
   Config + Token
   =========================== */
$configPath = __DIR__ . '/../config/config.json';
$config = json_decode((string)@file_get_contents($configPath), true) ?: [];

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$expected = (string)($config['security']['admin_token'] ?? '');
if ($expected !== '' && $token !== $expected) {
  http_response_code(403);
  echo "Forbidden (token).";
  exit;
}

/* ===========================
   Orders lokal sammeln
   =========================== */
function pv_try_decode_json_file(string $path): ?array {
  if (!is_file($path) || !is_readable($path)) return null;
  $raw = (string)file_get_contents($path);
  $arr = json_decode($raw, true);
  return is_array($arr) ? $arr : null;
}
function pv_extract_orders_from_any(array $raw): array {
  if (isset($raw['orders']) && is_array($raw['orders'])) $raw = $raw['orders'];
  if (isset($raw['data']) && is_array($raw['data'])) $raw = $raw['data'];

  $orders = [];
  if (is_array($raw) && array_is_list($raw)) {
    foreach ($raw as $o) if (is_array($o)) $orders[] = $o;
    return $orders;
  }
  if (is_array($raw) && !array_is_list($raw)) {
    $allValuesAreArrays = true;
    foreach ($raw as $k => $v) { if (!is_array($v)) { $allValuesAreArrays = false; break; } }
    if ($allValuesAreArrays) {
      foreach ($raw as $k => $v) {
        if (!is_array($v)) continue;
        if (empty($v['id']) && empty($v['order_id'])) $v['id'] = (string)$k;
        $orders[] = $v;
      }
      return $orders;
    }
  }
  return [];
}
function pv_collect_orders_local(): array {
  $orders = [];

  $candidates = [
    __DIR__ . '/../data/orders.json',
    __DIR__ . '/../data/order.json',
  ];
  foreach ($candidates as $p) {
    $raw = pv_try_decode_json_file($p);
    if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
  }

  $dir = __DIR__ . '/../data/orders';
  if (is_dir($dir)) {
    foreach (glob($dir . '/*.json') ?: [] as $f) {
      $raw = pv_try_decode_json_file($f);
      if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
    }
    foreach (glob($dir . '/*/*.json') ?: [] as $f) {
      $raw = pv_try_decode_json_file($f);
      if (is_array($raw)) $orders = array_merge($orders, pv_extract_orders_from_any($raw));
    }
  }

  $idx = [];
  foreach ($orders as $o) {
    if (!is_array($o)) continue;
    $id = (string)($o['id'] ?? $o['order_id'] ?? '');
    if ($id === '') $id = sha1(json_encode($o, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?: '');
    $idx[$id] = $o;
  }
  return array_values($idx);
}

function pv_order_date(array $o): string {
  $d = (string)($o['created_at_local'] ?? '');
  if ($d !== '') return $d;

  $d2 = (string)($o['date'] ?? $o['created_at'] ?? $o['ordered_at'] ?? '');
  if ($d2 === '') return '-';

  $ts = strtotime($d2);
  return $ts ? date('d.m.Y H:i', $ts) : $d2;
}
function pv_order_total_gross(array $o): ?float {
  if (isset($o['totals']) && is_array($o['totals'])) {
    if (isset($o['totals']['gross_after']) && is_numeric($o['totals']['gross_after'])) return (float)$o['totals']['gross_after'];
    if (isset($o['totals']['gross_before']) && is_numeric($o['totals']['gross_before'])) return (float)$o['totals']['gross_before'];
  }
  foreach (['total_gross','total','sum','amount'] as $k) {
    if (isset($o[$k]) && is_numeric($o[$k])) return (float)$o[$k];
  }
  return null;
}
function pv_order_total_net(array $o): ?float {
  if (isset($o['totals']) && is_array($o['totals'])) {
    if (isset($o['totals']['net_after']) && is_numeric($o['totals']['net_after'])) return (float)$o['totals']['net_after'];
    if (isset($o['totals']['net_before']) && is_numeric($o['totals']['net_before'])) return (float)$o['totals']['net_before'];
  }
  foreach (['total_net','net','sum_net'] as $k) {
    if (isset($o[$k]) && is_numeric($o[$k])) return (float)$o[$k];
  }
  return null;
}
function pv_order_items(array $o): array {
  $items = $o['items'] ?? $o['positions'] ?? $o['line_items'] ?? null;
  return is_array($items) ? $items : [];
}
function pv_order_customer(array $o): array {
  $c = $o['customer'] ?? $o['user'] ?? $o['billing'] ?? $o['address'] ?? null;
  return is_array($c) ? $c : [];
}
function pv_customer_email(array $o): string {
  foreach ([
    $o['email'] ?? null,
    $o['customer_email'] ?? null,
    $o['user_email'] ?? null,
    $o['billing_email'] ?? null,
    $o['customer']['email'] ?? null,
    $o['user']['email'] ?? null,
    $o['billing']['email'] ?? null,
    $o['address']['email'] ?? null,
  ] as $c) {
    $e = strtolower(trim((string)$c));
    if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) return $e;
  }
  return '';
}

/* ===========================
   Load order
   =========================== */
$view = preg_replace('~[^A-Za-z0-9_\-]~', '', (string)($_GET['view'] ?? 'order'));
$id = trim((string)($_GET['id'] ?? ''));

$allOrders = pv_collect_orders_local();
$order = null;
foreach ($allOrders as $o) {
  if (!is_array($o)) continue;
  $oid = (string)($o['id'] ?? $o['order_id'] ?? '');
  if ($oid !== '' && $oid === $id) { $order = $o; break; }
}
if (!is_array($order)) {
  http_response_code(404);
  echo "Order not found: " . h($id);
  exit;
}

$oid = (string)($order['id'] ?? $order['order_id'] ?? '');
$date = pv_order_date($order);
$status = (string)($order['status'] ?? '-');
$items = pv_order_items($order);
$gross = pv_order_total_gross($order);
$net   = pv_order_total_net($order);
$customer = pv_order_customer($order);
$customerEmail = pv_customer_email($order);

/* ===========================
   Invoice render (HTML)
   =========================== */
function pv_invoice_html(array $config, array $order, string $token): string {
  $shop = $config['shop'] ?? [];
  $company = (string)($shop['company_name'] ?? $shop['name'] ?? 'Shop');
  $addr1 = (string)($shop['address_line1'] ?? $shop['address'] ?? '');
  $addr2 = (string)($shop['address_line2'] ?? '');
  $vatid = (string)($shop['vat_id'] ?? $shop['ust_id'] ?? '');
  $email = (string)($shop['contact_email'] ?? $shop['email'] ?? '');

  $oid = (string)($order['id'] ?? $order['order_id'] ?? '');
  $date = pv_order_date($order);
  $status = (string)($order['status'] ?? '');

  $items = pv_order_items($order);
  $gross = pv_order_total_gross($order);
  $net   = pv_order_total_net($order);

  $cust = pv_order_customer($order);
  $custName = trim((string)($cust['name'] ?? (($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''))));
  $custStreet = trim((string)($cust['street'] ?? ''));
  $custZip = trim((string)($cust['zip'] ?? $cust['postal_code'] ?? ''));
  $custCity = trim((string)($cust['city'] ?? ''));
  $custCountry = trim((string)($cust['country'] ?? ''));
  $custEmail = pv_customer_email($order);

  $invoiceNo = (string)($order['invoice_no'] ?? $order['invoice_number'] ?? $oid);
  $invoiceDate = (string)($order['invoice_date'] ?? '');
  if ($invoiceDate === '') $invoiceDate = $date;

  $base = pv_base_url();
  $pdfUrl = $base . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') . '/admin_order.php?token=' . urlencode($token) . '&view=invoice_pdf&id=' . urlencode($oid);

  ob_start();
  ?>
  <!doctype html>
  <html lang="de">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rechnung <?= h($invoiceNo) ?></title>
    <style>
      body{ font-family: Arial, Helvetica, sans-serif; font-size: 12px; color:#111; margin: 24px; }
      .top{ display:flex; justify-content:space-between; gap:20px; }
      .box{ border:1px solid #ddd; padding:14px; border-radius:10px; }
      h1{ font-size:18px; margin:0 0 10px; }
      .muted{ color:#666; }
      table{ width:100%; border-collapse:collapse; margin-top:12px; }
      th,td{ border-bottom:1px solid #e6e6e6; padding:8px 6px; text-align:left; vertical-align:top; }
      th{ font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#555; }
      .right{ text-align:right; }
      .sum{ margin-top:12px; display:flex; justify-content:flex-end; }
      .sum table{ width:320px; }
      .sum td{ border:none; padding:4px 0; }
      .hr{ height:1px; background:#e6e6e6; margin:14px 0; }
      .actions{ margin-top:14px; }
      .btn{ display:inline-block; padding:10px 12px; border:1px solid #ddd; border-radius:10px; text-decoration:none; color:#111; }
      @media print { .actions{ display:none; } body{ margin: 0.8cm; } }
    </style>
  </head>
  <body>
    <div class="top">
      <div style="flex:1">
        <h1>Rechnung</h1>
        <div class="muted">Rechnungsnr.: <strong><?= h($invoiceNo) ?></strong></div>
        <div class="muted">Datum: <strong><?= h($invoiceDate) ?></strong></div>
        <?php if ($status !== ''): ?>
          <div class="muted">Status: <strong><?= h($status) ?></strong></div>
        <?php endif; ?>
      </div>
      <div class="box" style="min-width:280px">
        <div style="font-weight:700"><?= h($company) ?></div>
        <?php if ($addr1 !== ''): ?><div><?= h($addr1) ?></div><?php endif; ?>
        <?php if ($addr2 !== ''): ?><div><?= h($addr2) ?></div><?php endif; ?>
        <?php if ($vatid !== ''): ?><div class="muted" style="margin-top:6px;">USt-IdNr.: <?= h($vatid) ?></div><?php endif; ?>
        <?php if ($email !== ''): ?><div class="muted">E-Mail: <?= h($email) ?></div><?php endif; ?>
      </div>
    </div>

    <div class="hr"></div>

    <div class="box">
      <div style="font-weight:700; margin-bottom:6px;">Rechnung an</div>
      <div><?= h($custName !== '' ? $custName : '-') ?></div>
      <div><?= h(trim($custStreet)) ?></div>
      <div><?= h(trim($custZip . ' ' . $custCity)) ?></div>
      <div><?= h($custCountry) ?></div>
      <?php if ($custEmail !== ''): ?><div class="muted" style="margin-top:6px;"><?= h($custEmail) ?></div><?php endif; ?>
    </div>

    <table>
      <thead>
        <tr>
          <th>Artikel</th>
          <th>Menge</th>
          <th class="right">Preis</th>
          <th class="right">Summe</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): if (!is_array($it)) continue;
          $name = (string)($it['name'] ?? $it['title'] ?? $it['sku'] ?? $it['article'] ?? $it['id'] ?? 'Artikel');
          $qty  = (float)($it['qty'] ?? $it['quantity'] ?? 1);
          $price= $it['price'] ?? $it['unit_price'] ?? null;
          $sum  = $it['sum'] ?? $it['total'] ?? null;

          $priceF = is_numeric($price) ? (float)$price : null;
          $sumF   = is_numeric($sum) ? (float)$sum : null;
        ?>
          <tr>
            <td><?= h($name) ?></td>
            <td><?= h((string)$qty) ?></td>
            <td class="right"><?= $priceF !== null ? h(pv_money($priceF)) : h((string)$price) ?></td>
            <td class="right"><?= $sumF !== null ? h(pv_money($sumF)) : h((string)$sum) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="sum">
      <table>
        <?php if ($net !== null): ?>
          <tr><td class="muted">Netto</td><td class="right"><?= h(pv_money($net)) ?></td></tr>
        <?php endif; ?>
        <?php if ($gross !== null): ?>
          <tr><td style="font-weight:700">Brutto</td><td class="right" style="font-weight:700"><?= h(pv_money($gross)) ?></td></tr>
        <?php endif; ?>
      </table>
    </div>

    <div class="actions">
      <a class="btn" href="<?= h($pdfUrl) ?>">PDF herunterladen</a>
      <a class="btn" href="admin_order.php?token=<?= h(urlencode($token)) ?>&view=order&id=<?= h(urlencode($oid)) ?>" style="margin-left:8px;">Zur Bestellung</a>
      <a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>&view=customers" style="margin-left:8px;">Zur Kundenverwaltung</a>
    </div>
  </body>
  </html>
  <?php
  return (string)ob_get_clean();
}

/* ===========================
   VIEW: order / invoice / invoice_pdf
   =========================== */
if ($view === 'invoice') {
  echo pv_invoice_html($config, $order, $token);
  exit;
}

if ($view === 'invoice_pdf') {
  $html = pv_invoice_html($config, $order, $token);

  // ✅ Dompdf optional
  $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
  $dompdfAutoload = __DIR__ . '/../dompdf/autoload.inc.php';

  if (is_file($vendorAutoload)) @require_once $vendorAutoload;
  elseif (is_file($dompdfAutoload)) @require_once $dompdfAutoload;

  if (class_exists(\Dompdf\Dompdf::class)) {
    $dompdf = new \Dompdf\Dompdf([
      'isRemoteEnabled' => true,
      'isHtml5ParserEnabled' => true,
    ]);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'invoice_' . preg_replace('~[^A-Za-z0-9_\-]~', '_', $oid) . '.pdf';
    // Attachment => Download, 0 => inline
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
  }

  // Fallback: druckbares HTML (kein PDF-Generator vorhanden)
  http_response_code(200);
  echo $html;
  exit;
}

/* ===========================
   VIEW: order (default)
   =========================== */
$backUrl = 'admin.php?token=' . urlencode($token) . '&view=customers';

?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin · Order <?= h($oid) ?></title>
  <link rel="stylesheet" href="assets/styles.css">
  <style>
    :root{ --border: rgba(0,0,0,.14); --muted: rgba(0,0,0,.65); }
    body{ background:#fff; }
    .pv-table{ width:100%; border-collapse:collapse; }
    .pv-table th, .pv-table td{ padding:10px; border-bottom:1px solid var(--border); font-size:14px; text-align:left; vertical-align:top; }
    .pv-table th{ font-size:12px; letter-spacing:.05em; text-transform:uppercase; opacity:.8; }
    .pv-pill{ display:inline-block; padding:4px 10px; border-radius:999px; border:1px solid var(--border); font-size:12px; }
    .muted{ color: var(--muted); font-size:12px; }
    .row{ display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:space-between; }
    .btn{ display:inline-block; height:32px; line-height:30px; padding:0 10px; border:1px solid var(--border); border-radius:10px; text-decoration:none; color:#111; background:rgba(0,0,0,.02); }
    .btn:hover{ border-color: rgba(149,191,32,.55); }
    .card{ border:1px solid var(--border); border-radius:14px; padding:12px; margin-bottom:12px; }
    pre{ white-space:pre-wrap; word-break:break-word; background:rgba(0,0,0,.03); padding:12px; border-radius:12px; border:1px solid var(--border); }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div>
        <div class="h-title">Bestellung</div>
        <div class="h-sub">
          <span class="pv-pill"><?= h($oid) ?></span>
          <span class="muted">· <?= h($date) ?> · Status: <?= h($status) ?></span>
        </div>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <a class="btn" href="admin_order.php?token=<?= h(urlencode($token)) ?>&view=invoice&id=<?= h(urlencode($oid)) ?>">Rechnung</a>
        <a class="btn" href="admin_order.php?token=<?= h(urlencode($token)) ?>&view=invoice_pdf&id=<?= h(urlencode($oid)) ?>">PDF</a>
        <a class="btn" href="<?= h($backUrl) ?>">Kunden</a>
        <a class="btn" href="admin.php?token=<?= h(urlencode($token)) ?>">Admin</a>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:800; margin-bottom:8px;">Kunde</div>
      <table class="pv-table">
        <tbody>
          <tr><th style="width:180px;">Name</th><td><?= h(trim((string)($customer['name'] ?? (($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? '')))) ?: '-') ?></td></tr>
          <tr><th>E-Mail</th><td><?= h($customerEmail !== '' ? $customerEmail : '-') ?></td></tr>
          <tr><th>Adresse</th><td><?= h(trim((string)($customer['street'] ?? ''))) ?> <?= h(trim((string)($customer['zip'] ?? $customer['postal_code'] ?? ''))) ?> <?= h(trim((string)($customer['city'] ?? ''))) ?> <?= h(trim((string)($customer['country'] ?? ''))) ?></td></tr>
        </tbody>
      </table>
    </div>

    <div class="card">
      <div style="font-weight:800; margin-bottom:8px;">Positionen</div>
      <?php if (empty($items)): ?>
        <div class="muted">Keine Items im Order-JSON gefunden.</div>
      <?php else: ?>
        <table class="pv-table">
          <thead>
            <tr>
              <th>Artikel</th>
              <th>Menge</th>
              <th>Preis</th>
              <th>Summe</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): if (!is_array($it)) continue;
              $name = (string)($it['name'] ?? $it['title'] ?? $it['sku'] ?? $it['article'] ?? $it['id'] ?? 'Artikel');
              $qty  = (string)($it['qty'] ?? $it['quantity'] ?? '1');
              $price= (string)($it['price'] ?? $it['unit_price'] ?? '');
              $sum  = (string)($it['sum'] ?? $it['total'] ?? '');
            ?>
              <tr>
                <td><?= h($name) ?></td>
                <td><?= h($qty) ?></td>
                <td><?= h($price) ?></td>
                <td><?= h($sum) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

      <div style="height:10px"></div>
      <div class="row">
        <div class="muted">Netto: <?= h($net !== null ? pv_money($net) : '-') ?></div>
        <div style="font-weight:800;">Brutto: <?= h($gross !== null ? pv_money($gross) : '-') ?></div>
      </div>
    </div>

    <div class="card">
      <div style="font-weight:800; margin-bottom:8px;">Raw JSON</div>
      <pre><?= h(json_encode($order, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT) ?: '') ?></pre>
    </div>
  </div>
</body>
</html>
