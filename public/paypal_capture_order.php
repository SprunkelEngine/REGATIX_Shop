<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../src/Repository.php';

function jsonOut(int $code, array $data): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function paypalBaseUrl(string $mode): string {
  return $mode === 'sandbox'
    ? 'https://api-m.sandbox.paypal.com'
    : 'https://api-m.paypal.com';
}

function curlJson(string $method, string $url, array $headers = [], ?string $body = null): array {
  if (!function_exists('curl_init')) {
    return ['ok' => false, 'code' => 0, 'raw' => null, 'json' => null, 'err' => 'PHP cURL extension fehlt'];
  }

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST  => $method,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_TIMEOUT        => 30,
  ]);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($resp === false) {
    return ['ok' => false, 'code' => 0, 'raw' => null, 'json' => null, 'err' => $err ?: 'cURL error'];
  }

  $json = json_decode($resp, true);
  return [
    'ok'   => ($code >= 200 && $code < 300),
    'code' => $code,
    'raw'  => $resp,
    'json' => $json,
    'err'  => null
  ];
}

function getAccessToken(string $baseUrl, string $clientId, string $secret): array {
  $auth = base64_encode($clientId . ':' . $secret);

  $res = curlJson('POST', $baseUrl . '/v1/oauth2/token', [
    'Authorization: Basic ' . $auth,
    'Content-Type: application/x-www-form-urlencoded',
    'Accept: application/json',
    'Accept-Language: en_US',
  ], 'grant_type=client_credentials');

  if (!$res['ok'] || empty($res['json']['access_token'])) {
    return ['ok' => false, 'error' => 'PayPal Token fehlgeschlagen', 'res' => $res];
  }

  return ['ok' => true, 'token' => (string)$res['json']['access_token']];
}

try {
  $repo = new PV\Repository(__DIR__ . '/../config/config.json');
  $cfg  = $repo->getConfig();

  $shop   = (array)($cfg['shop'] ?? []);
  $paypal = (array)($shop['paypal'] ?? []);

  if (empty($paypal['enabled'])) {
    jsonOut(400, ['error' => 'PayPal ist deaktiviert.']);
  }

  $debug = !empty($paypal['debug']);

  $mode = strtolower((string)($paypal['mode'] ?? 'live'));
  if ($mode !== 'sandbox' && $mode !== 'live') $mode = 'live';

  $clientId = $mode === 'sandbox'
    ? (string)($paypal['client_id_sandbox'] ?? ($paypal['client_id'] ?? ''))
    : (string)($paypal['client_id_live']    ?? ($paypal['client_id'] ?? ''));

  $secret = $mode === 'sandbox'
    ? (string)($paypal['client_secret_sandbox'] ?? ($paypal['client_secret'] ?? ''))
    : (string)($paypal['client_secret_live']    ?? ($paypal['client_secret'] ?? ''));

  if ($clientId === '' || $secret === '') {
    jsonOut(400, ['error' => 'PayPal Config fehlt (Client-ID/Secret).']);
  }

  $raw = file_get_contents('php://input') ?: '';
  $in  = json_decode($raw, true);
  if (!is_array($in)) $in = $_POST;

  $orderId = (string)($in['order_id'] ?? '');
  if ($orderId === '') {
    jsonOut(400, ['error' => 'order_id fehlt.']);
  }

  $baseUrl = paypalBaseUrl($mode);

  $tok = getAccessToken($baseUrl, $clientId, $secret);
  if (!$tok['ok']) {
    jsonOut(400, [
      'error'  => $tok['error'],
      'detail' => $debug ? ($tok['res']['raw'] ?? $tok['res']['err'] ?? 'unknown') : null
    ]);
  }

  $token = $tok['token'];

  $res = curlJson('POST', $baseUrl . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'Accept: application/json',
    'PayPal-Request-Id: ' . bin2hex(random_bytes(16)),
  ], '{}');

  if (!$res['ok']) {
    jsonOut(400, [
      'error'  => 'PayPal Capture fehlgeschlagen',
      'detail' => $debug ? ($res['raw'] ?? $res['err'] ?? 'unknown') : null
    ]);
  }

  $status = (string)($res['json']['status'] ?? '');
  $captureId = $res['json']['purchase_units'][0]['payments']['captures'][0]['id'] ?? '';

  jsonOut(200, [
    'status' => $status,
    'capture_id' => (string)$captureId
  ]);

} catch (Throwable $e) {
  jsonOut(500, ['error' => 'Serverfehler', 'detail' => $e->getMessage()]);
}
