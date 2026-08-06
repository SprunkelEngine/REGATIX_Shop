<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/freight_lib.php';

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) $data = [];

$orderJson = (string)($data['order_json'] ?? '');
$order = null;

if ($orderJson !== '') {
  $tmp = json_decode($orderJson, true);
  if (is_array($tmp) || is_object($tmp)) $order = $tmp;
}

$payload = [
  'zip'    => (string)($data['zip'] ?? ''),
  'pickup' => !empty($data['pickup']),
  'order'  => $order,
];

$out = pv_calc_freight($payload);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
