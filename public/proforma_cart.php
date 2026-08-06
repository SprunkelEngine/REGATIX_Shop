<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Repository.php';
require_once __DIR__ . '/../src/Proforma.php';

use PV\Proforma;

function fail(int $code, string $msg): never {
  http_response_code($code);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $msg;
  exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') fail(400, 'No payload');

$data = json_decode($raw, true);
if (!is_array($data)) fail(400, 'Invalid JSON');

$items = $data['items'] ?? null;
if (!is_array($items) || count($items) < 1) fail(400, 'Cart empty');

// Basic limits (avoid abuse)
if (strlen($raw) > 1_500_000) fail(413, 'Payload too large');

$repo = new PV\Repository(__DIR__ . '/../config/config.json');
$cfg  = $repo->getConfig();
$boot = $repo->bootstrap();

$shop = (array)($cfg['shop'] ?? []);

$title = (string)($boot['content']['product']['title'] ?? 'Produkt');
$createdAt = date('c');

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

$sumGross = 0.0; $anyGross = false;
$sumNet = 0.0;   $anyNet = false;

foreach ($items as $it) {
  if (!is_array($it)) continue;
  $qty = max(1, (int)($it['quantity'] ?? 1));

  $ug = toFloatDE($it['price_gross_unit'] ?? null);
  $un = toFloatDE($it['price_net_unit'] ?? null);

  if ($ug !== null) { $sumGross += $ug * $qty; $anyGross = true; }
  if ($un !== null) { $sumNet   += $un * $qty; $anyNet = true; }
}

$order = [
  'created_at' => $createdAt,
  'product_title' => $title,
  'mode' => 'cart_preview',
  'totals' => [
	'gross' => $anyGross ? $sumGross : null,
	'net'   => $anyNet ? $sumNet : null,
  ],
  'items' => $items,
];

// customer (optional in preview)
$custIn = $data['customer'] ?? [];
if (!is_array($custIn)) { $custIn = []; }

$s = static function($v, int $max=160): string {
  $t = trim((string)($v ?? ''));
  if ($t === '') return '';
  if (function_exists('mb_strlen') && function_exists('mb_substr')) {
    if (mb_strlen($t, 'UTF-8') > $max) $t = mb_substr($t, 0, $max, 'UTF-8');
  } else {
    if (strlen($t) > $max) $t = substr($t, 0, $max);
  }
  return $t;
};


$customer = [
  'email'      => $s($custIn['email'] ?? ''),
  'first_name' => $s($custIn['first_name'] ?? ''),
  'last_name'  => $s($custIn['last_name'] ?? ''),
  'company'    => $s($custIn['company'] ?? '', 200),
  'street'     => $s($custIn['street'] ?? '', 200),
  'zip'        => $s($custIn['zip'] ?? '', 32),
  'city'       => $s($custIn['city'] ?? '', 120),
  'country'    => $s($custIn['country'] ?? '', 120),
];


$invoiceNo = 'PF-VORSCHAU-' . date('Ymd-His');

$tmp = tempnam(sys_get_temp_dir(), 'pv_pf_');
if ($tmp === false) fail(500, 'Tempfile error');
$pdfFile = $tmp . '.pdf';

try {
  Proforma::createPdf($order, $customer, $shop, $pdfFile, $invoiceNo, true);
} catch (Throwable $e) {
  @unlink($tmp);
  fail(500, 'PDF error: ' . $e->getMessage());
}

if (!is_file($pdfFile)) {
  @unlink($tmp);
  fail(500, 'PDF not created');
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $invoiceNo . '.pdf"');
header('Content-Length: ' . filesize($pdfFile));
readfile($pdfFile);

@unlink($pdfFile);
@unlink($tmp);
