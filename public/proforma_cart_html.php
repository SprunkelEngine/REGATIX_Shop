<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/HtmlTemplate.php';

use PV\HtmlTemplate;

function fail(int $code, string $msg): never {
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $msg;
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === false) fail(400, 'No input');

$payload = json_decode($raw, true);
if (!is_array($payload)) fail(400, 'Invalid JSON');

$items = $payload['items'] ?? [];
$customer = $payload['customer'] ?? [];
if (!is_array($items) || !is_array($customer)) fail(400, 'Invalid payload');

$repo = new PV\Repository(dirname(__DIR__));
$cfg  = $repo->getConfig();
$boot = $repo->bootstrap();

$shop = (array)($cfg['shop'] ?? []);
$vatRate = (float)($shop['vat_rate'] ?? 0.19);

function toFloatDE($v): ?float {
  if ($v === null || $v === '') return null;
  if (is_int($v) || is_float($v)) return (float)$v;
  $s = trim((string)$v);
  $s = preg_replace('/[^\d,.\-]/', '', $s ?? '');
  if ($s === '') return null;
  if (str_contains($s, '.') && str_contains($s, ',')) {
    $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
  } else {
    $s = str_replace(',', '.', $s);
  }
  $n = (float)$s;
  return is_finite($n) ? $n : null;
}

function eur(?float $n): string {
  if ($n === null) return '';
  // German formatting: 1.234,56 €
  $s = number_format($n, 2, ',', '.');
  return $s . ' €';
}

$rows = [];
$sumGross = 0.0; $anyGross = false;
$sumNet = 0.0;   $anyNet = false;

foreach ($items as $it) {
  if (!is_array($it)) continue;
  $qty = max(1, (int)($it['quantity'] ?? 1));
  $article = (string)($it['article'] ?? $it['Artiklnummer'] ?? $it['sku'] ?? '');
  $desc = (string)($it['label'] ?? $it['selection_text'] ?? $it['title'] ?? '');
  if ($desc === '' && isset($it['selection']) && is_array($it['selection'])) {
    $parts = [];
    foreach ($it['selection'] as $k => $v) {
      if ($k === 'Produktart') continue;
      $parts[] = $k . ': ' . $v;
    }
    $desc = implode(', ', $parts);
  }

  $grossUnit = toFloatDE($it['price_gross'] ?? $it['gross_unit'] ?? $it['price'] ?? null);
  $netUnit = toFloatDE($it['price_net'] ?? $it['net_unit'] ?? null);

  $grossLine = $grossUnit !== null ? $grossUnit * $qty : null;
  $netLine = $netUnit !== null ? $netUnit * $qty : null;

  if ($grossLine !== null) { $sumGross += $grossLine; $anyGross = true; }
  if ($netLine !== null)   { $sumNet += $netLine;     $anyNet = true; }

  $rows[] = [
    'article' => $article,
    'description' => $desc,
    'qty' => (string)$qty,
    'unit_price' => eur($grossUnit ?? $netUnit),
    'line_total' => eur($grossLine ?? $netLine),
  ];
}

$totalGross = $anyGross ? $sumGross : null;
$totalNet = $anyNet ? $sumNet : ($anyGross ? ($sumGross / (1.0 + $vatRate)) : null);
$totalVat = ($totalGross !== null && $totalNet !== null) ? ($totalGross - $totalNet) : null;

$invoiceNo = 'PF-VORSCHAU-' . date('Ymd-His');
$dateIso = date('Y-m-d');
$datePretty = date('d.m.Y');

$customer_company = (string)($customer['company'] ?? '');
$customer_name = trim((string)($customer['first_name'] ?? '') . ' ' . (string)($customer['last_name'] ?? ''));
$customer_street = (string)($customer['street'] ?? '');
$customer_zip_city = trim((string)($customer['zip'] ?? '') . ' ' . (string)($customer['city'] ?? ''));
$customer_country = (string)($customer['country'] ?? '');
$customer_email = (string)($customer['email'] ?? '');
$customer_phone = (string)($customer['phone'] ?? '');

$noteHtml = (string)($shop['proforma_note_html'] ?? '<p>Bitte prüfen Sie die Angaben. Dieses Dokument ist eine Vorschau.</p>');

$tplPath = dirname(__DIR__) . '/data/letterhead/proforma_template.html';
$cssPath = dirname(__DIR__) . '/data/letterhead/proforma_template.css';
if (!is_file($tplPath) || !is_file($cssPath)) fail(500, 'Template missing');

$tpl = file_get_contents($tplPath);
$css = file_get_contents($cssPath);
if ($tpl === false || $css === false) fail(500, 'Template read error');

// Inline CSS (so user only needs 2 files; but still keep CSS editable)
$tpl = str_replace('<link rel="stylesheet" href="proforma_template.css">', '<style>' . $css . '</style>', $tpl);

$html = HtmlTemplate::render($tpl, [
  'invoice_no' => $invoiceNo,
  'date_iso' => $dateIso,
  'date_pretty' => $datePretty,
  'vat_rate_percent' => (string)round($vatRate * 100),
  'customer_company' => $customer_company,
  'customer_name' => $customer_name,
  'customer_street' => $customer_street,
  'customer_zip_city' => $customer_zip_city,
  'customer_country' => $customer_country,
  'customer_email' => $customer_email,
  'customer_phone' => $customer_phone,
  'items' => $rows,
  'total_gross' => eur($totalGross),
  'total_net' => eur($totalNet),
  'total_vat' => eur($totalVat),
  'note_html' => $noteHtml,
]);

header('Content-Type: text/html; charset=UTF-8');
echo $html;
