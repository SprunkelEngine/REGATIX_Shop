<?php
declare(strict_types=1);

function fail(int $code, string $msg): never {
  http_response_code($code);
  echo $msg;
  exit;
}

$id = (string)($_GET['id'] ?? '');
$k  = (string)($_GET['k'] ?? '');
if ($id === '' || $k === '') fail(400, 'Bad Request');

$base = realpath(__DIR__ . '/../data/orders');
if (!$base) fail(500, 'Orders directory missing');

$foundFile = '';
$foundKey  = '';
$invoicePath = '';

// Search in JSONL files
$files = glob($base . '/orders-*.jsonl') ?: [];
foreach ($files as $f) {
  $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!$lines) continue;

  foreach ($lines as $line) {
	$row = json_decode($line, true);
	if (!is_array($row)) continue;

	if (($row['invoice_no'] ?? '') === $id) {
	  $foundKey = (string)($row['invoice_key'] ?? '');
	  $invoicePath = (string)($row['invoice_file'] ?? '');
	  $foundFile = $f;
	  break 2;
	}
  }
}

if ($foundFile === '') fail(404, 'Not found');
if (!hash_equals($foundKey, $k)) fail(403, 'Forbidden');

$full = realpath($invoicePath);
if (!$full || !is_file($full)) fail(404, 'Invoice missing');

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . basename($full) . '"');
header('Content-Length: ' . filesize($full));
readfile($full);
