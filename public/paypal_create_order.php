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

function parseIncoming(): array {
  $raw = file_get_contents('php://input') ?: '';
  $in  = json_decode($raw, true);

  // Fallback für Fälle, wo JSON nicht sauber durchkommt
  if (!is_array($in)) $in = $_POST;

  if (!is_array($in) || empty($in)) {
    jsonOut(400, [
      'error' => 'Ungültiges JSON im Request.',
      'debug' => [
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
        'raw_len'      => strlen($raw),
        'raw_preview'  => substr($raw, 0, 200),
      ]
    ]);
  }

  return $in;
}

/**
 * Robust: akzeptiert "1.234,56 €", "123,45", "123.45", 123.45
 * Gibt "1234.56" zurück (2 Dezimalstellen, Punkt).
 */
function toAmountString($val): string {
  if ($val === null) return '';

  if (is_string($val)) {
    $s = trim($val);
    $s = preg_replace('/[^\d,.\-]/', '', $s); // € / Leerzeichen weg

    if ($s === '' || $s === '-') return '';

    // Komma + Punkt => Punkt = Tausender, Komma = Dezimal
    if (str_contains($s, ',') && str_contains($s, '.')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    } else {
      // Komma => Dezimalpunkt
      $s = str_replace(',', '.', $s);
    }

    if (!is_numeric($s)) return '';
    $f = (float)$s;
  } elseif (is_numeric($val)) {
    $f = (float)$val;
  } else {
    return '';
  }

  if ($f <= 0) return '';
  return number_format($f, 2, '.', '');
}

function decodeOrderJson($orderJson): array {
  if (is_string($orderJson)) {
    $tmp = json_decode($orderJson, true);
    return is_array($tmp) ? $tmp : [];
  }
  return is_array($orderJson) ? $orderJson : [];
}

/**
 * Optionaler Fallback, falls du später wieder serverseitig aus order_json ziehen willst.
 * Aktuell ist die Empfehlung: amount immer vom Frontend mitsenden.
 */
function computeGrossAmountEUR(array $order): string {
  foreach (['gross_after','sum_gross','total_gross','gross','amount_gross','grand_total'] as $k) {
    if (isset($order[$k])) {
      $a = toAmountString($order[$k]);
      if ($a !== '') return $a;
    }
  }

  $sum = 0.0;
  if (!empty($order['items']) && is_array($order['items'])) {
    foreach ($order['items'] as $it) {
      $qty = $it['qty'] ?? $it['quantity'] ?? 1;
      $qtyF = is_numeric($qty) ? (float)$qty : 1.0;

      $p = $it['price_gross'] ?? $it['gross'] ?? $it['price'] ?? null;
      $pStr = toAmountString($p);
      if ($pStr !== '') $sum += $qtyF * (float)$pStr;
    }
  }

  if ($sum <= 0) return '';
  return number_format($sum, 2, '.', '');
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

  $in = parseIncoming();

  // 1) bevorzugt amount vom Frontend (robust)
  $amount = '';
  if (isset($in['amount'])) {
    $amount = toAmountString($in['amount']);
  }

  // 2) Fallback: aus order_json versuchen
  if ($amount === '') {
    $order = decodeOrderJson($in['order_json'] ?? null);
    if (!empty($order)) {
      $amount = computeGrossAmountEUR($order);
    }
  }

  if ($amount === '') {
    jsonOut(400, ['error' => 'Summe konnte nicht ermittelt werden (order_json).']);
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

  $payload = [
    'intent' => 'CAPTURE',
    'purchase_units' => [[
      'amount' => [
        'currency_code' => 'EUR',
        'value' => $amount,
      ],
    ]],
    'application_context' => [
      'shipping_preference' => 'NO_SHIPPING',
      'user_action' => 'PAY_NOW',
    ],
  ];

  $res = curlJson('POST', $baseUrl . '/v2/checkout/orders', [
    'Authorization: Bearer ' . $token,
    'Content-Type: application/json',
    'Accept: application/json',
    'PayPal-Request-Id: ' . bin2hex(random_bytes(16)),
  ], json_encode($payload));

  if (!$res['ok'] || empty($res['json']['id'])) {
    jsonOut(400, [
      'error'  => 'PayPal CreateOrder fehlgeschlagen',
      'detail' => $debug ? ($res['raw'] ?? $res['err'] ?? 'unknown') : null
    ]);
  }

  jsonOut(200, ['id' => (string)$res['json']['id']]);

} catch (Throwable $e) {
  jsonOut(500, ['error' => 'Serverfehler', 'detail' => $e->getMessage()]);
}
